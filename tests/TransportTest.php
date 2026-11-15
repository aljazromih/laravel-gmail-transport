<?php

use AljazRomih\GmailTransport\Exceptions\GmailAccountNeedsReconnectException;
use AljazRomih\GmailTransport\Exceptions\GmailTransportException;
use AljazRomih\GmailTransport\Exceptions\NoGmailAccountException;
use AljazRomih\GmailTransport\GmailTransport;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\RawMessage;

const SEND_URL = 'https://gmail.googleapis.com/upload/gmail/v1/users/me/messages/send?uploadType=media';

function gmailAccepts(string $id = 'gmail-id'): void
{
    Http::fake(['gmail.googleapis.com/*' => Http::response(['id' => $id, 'threadId' => 'thread', 'labelIds' => ['SENT']])]);
}

/**
 * @return list<Request>
 */
function gmailRequests(): array
{
    return Http::recorded(fn (Request $request): bool => $request->url() === SEND_URL)->map(fn (array $pair): Request => $pair[0])->values()->all();
}

it('registers the gmail transport', function () {
    $transport = Mail::mailer('gmail')->getSymfonyTransport();

    expect($transport)->toBeInstanceOf(GmailTransport::class)
        ->and((string) $transport)->toBe('gmail+api://default');
});

it('uploads the raw message with the account\'s token and keeps Gmail\'s id', function () {
    account('ana@example.com');
    gmailAccepts('gmail-id-1');

    $sent = Mail::mailer('gmail')->raw('Invoice attached.', function (Message $message): void {
        $message->from('ana@example.com', 'Ana')->to('client@example.com')->subject('Invoice 2026-1')
            ->attachData('%PDF-1.4', 'invoice.pdf', ['mime' => 'application/pdf']);
    });

    expect($sent?->getMessageId())->toBe('gmail-id-1');

    Http::assertSent(fn (Request $request): bool => $request->url() === SEND_URL
        && $request->method() === 'POST'
        && $request->hasHeader('Authorization', 'Bearer access-ana')
        && $request->hasHeader('Content-Type', 'message/rfc822')
        && str_contains($request->body(), "To: client@example.com\r\n")
        && str_contains($request->body(), "Subject: Invoice 2026-1\r\n")
        && str_contains($request->body(), 'filename=invoice.pdf'));
});

it('writes the Bcc recipients back as a header, because Symfony leaves them out', function () {
    account('ana@example.com');
    gmailAccepts();

    Mail::mailer('gmail')->raw('Body', function (Message $message): void {
        $message->from('ana@example.com')->to('client@example.com')->cc('Boss@Example.com')
            ->bcc('boss@example.com')->bcc('ziga@example.com', 'Žiga Novak');
    });

    $body = gmailRequests()[0]->body();
    $bcc = strstr($body, "\r\n", true);

    expect($body)->toStartWith('Bcc: ')
        // The header ends with its own CRLF, so the next header starts on a new line.
        ->and($body)->toContain("<ziga@example.com>\r\n")
        ->and($bcc)->toContain('<ziga@example.com>')->toContain('=?utf-8?')
        // Already a Cc recipient, in another case: not repeated as Bcc.
        ->and(strtolower((string) $bcc))->not->toContain('boss@');
});

it('adds no Bcc header when there is no hidden recipient', function () {
    account('ana@example.com');
    gmailAccepts();

    Mail::mailer('gmail')->raw('Body', fn (Message $message) => $message->from('ana@example.com')->to('client@example.com'));

    expect(gmailRequests()[0]->body())->not->toContain('Bcc:');
});

it('picks the account by the From header, also when Sender and Return-Path name another one', function () {
    account('ana@example.com');
    account('bounces@example.com');
    gmailAccepts();

    Mail::mailer('gmail')->raw('Body', function (Message $message): void {
        $message->from('Ana@Example.com')->sender('bounces@example.com')->returnPath('bounces@example.com')->to('client@example.com');
    });

    expect(gmailRequests()[0]->header('Authorization'))->toBe(['Bearer access-ana']);
});

it('uses the envelope sender for a raw message and leaves its headers alone', function () {
    account('ana@example.com');
    gmailAccepts();

    Mail::mailer('gmail')->getSymfonyTransport()->send(
        new RawMessage("From: someone@example.com\r\nTo: client@example.com\r\nSubject: Raw\r\n\r\nBody"),
        new Envelope(new Address('ana@example.com'), [new Address('client@example.com')]),
    );

    $request = gmailRequests()[0];

    expect($request->header('Authorization'))->toBe(['Bearer access-ana'])
        ->and($request->body())->toStartWith('From: someone@example.com');
});

it('refuses a From address without a connected account, before calling Google', function () {
    Http::fake();

    expect(fn () => Mail::mailer('gmail')->raw('Body', fn (Message $message) => $message->from('nobody@example.com')->to('client@example.com')))
        ->toThrow(NoGmailAccountException::class, 'nobody@example.com');

    expect(new NoGmailAccountException('x'))->toBeInstanceOf(TransportExceptionInterface::class);
    Http::assertNothingSent();
});

it('refuses an account that has to be connected again, before calling Google', function () {
    account('ana@example.com', needsReconnect: true);
    Http::fake();

    expect(fn () => Mail::mailer('gmail')->raw('Body', fn (Message $message) => $message->from('ana@example.com')->to('client@example.com')))
        ->toThrow(GmailAccountNeedsReconnectException::class);

    Http::assertNothingSent();
});

it('refreshes an access token that is about to expire, then keeps the new one', function () {
    $account = account('ana@example.com', expiresAt: now()->addSeconds(30));
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-fresh', 'expires_in' => 3599]),
        'gmail.googleapis.com/*' => Http::response(['id' => 'gmail-id']),
    ]);

    Mail::mailer('gmail')->raw('Body', fn (Message $message) => $message->from('ana@example.com')->to('client@example.com'));

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://oauth2.googleapis.com/token'
        && $request['grant_type'] === 'refresh_token'
        && $request['refresh_token'] === 'refresh-ana'
        && $request['client_id'] === 'test-client-id'
        && $request['client_secret'] === 'test-client-secret');

    expect(gmailRequests()[0]->header('Authorization'))->toBe(['Bearer access-fresh'])
        ->and($account->fresh())
        ->access_token->toBe('access-fresh')
        ->expires_at->isAfter(now()->addMinutes(59))->toBeTrue();
});

it('refreshes once and retries when Gmail rejects a token that looked valid', function () {
    account('ana@example.com');
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-fresh', 'expires_in' => 3599]),
        'gmail.googleapis.com/*' => Http::sequence()
            ->push(['error' => ['code' => 401, 'message' => 'Invalid Credentials']], 401)
            ->push(['id' => 'gmail-id']),
    ]);

    Mail::mailer('gmail')->raw('Body', fn (Message $message) => $message->from('ana@example.com')->to('client@example.com'));

    expect(array_map(fn (Request $request): array => $request->header('Authorization'), gmailRequests()))
        ->toBe([['Bearer access-ana'], ['Bearer access-fresh']]);
});

it('marks the account for reconnecting when Google no longer accepts its refresh token', function () {
    $account = account('ana@example.com', expiresAt: now()->subMinute());
    Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.'], 400)]);

    expect(fn () => Mail::mailer('gmail')->raw('Body', fn (Message $message) => $message->from('ana@example.com')->to('client@example.com')))
        ->toThrow(GmailAccountNeedsReconnectException::class);

    expect($account->fresh()->needsReconnect())->toBeTrue()
        ->and(gmailRequests())->toBe([]);
});

it('passes Gmail\'s refusal on without asking for a reconnect', function (array $responses, string $message) {
    $account = account('ana@example.com');
    $sequence = Http::sequence();

    foreach ($responses as [$status, $body]) {
        $sequence->push($body, $status);
    }

    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-fresh', 'expires_in' => 3599]),
        'gmail.googleapis.com/*' => $sequence,
    ]);

    expect(fn () => Mail::mailer('gmail')->raw('Body', fn (Message $m) => $m->from('ana@example.com')->to('client@example.com')))
        ->toThrow(GmailTransportException::class, $message);

    expect($account->fresh()->needsReconnect())->toBeFalse()
        ->and(gmailRequests())->toHaveCount(count($responses));
})->with([
    'a rate limit' => [[[403, ['error' => ['code' => 403, 'message' => 'User-rate limit exceeded']]]], '403 User-rate limit exceeded'],
    'a server error' => [[[503, ['error' => ['code' => 503, 'message' => 'Backend Error']]]], '503 Backend Error'],
    'a second 401' => [[[401, ['error' => ['code' => 401, 'message' => 'Invalid Credentials']]], [401, ['error' => ['code' => 401, 'message' => 'Invalid Credentials']]]], '401 Invalid Credentials'],
]);

it('puts no token into its exception messages', function () {
    account('ana@example.com', expiresAt: now()->subMinute());
    Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_client', 'error_description' => 'Unauthorized'], 401)]);

    try {
        Mail::mailer('gmail')->raw('Body', fn (Message $message) => $message->from('ana@example.com')->to('client@example.com'));
        $this->fail('The send should have failed.');
    } catch (GmailTransportException $exception) {
        expect($exception->getMessage())->toBe('Google refused the token request: 401 Unauthorized')
            ->not->toContain('refresh-ana')->not->toContain('test-client-secret');
    }
});

it('turns a network failure into a transport exception', function () {
    account('ana@example.com');
    Http::fake(fn () => throw new ConnectionException('Connection timed out'));

    expect(fn () => Mail::mailer('gmail')->raw('Body', fn (Message $message) => $message->from('ana@example.com')->to('client@example.com')))
        ->toThrow(GmailTransportException::class, 'Gmail could not be reached');
});
