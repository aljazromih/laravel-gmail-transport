<?php

use AljazRomih\GmailTransport\GmailAccount;
use AljazRomih\GmailTransport\Tests\Fixtures\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

const SEND_SCOPE = 'openid https://www.googleapis.com/auth/userinfo.email https://www.googleapis.com/auth/gmail.send';

/**
 * Google's answers for a granted consent.
 */
function googleGrants(string $email = 'ana@example.com', string $scope = SEND_SCOPE): void
{
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'access-new',
            'expires_in' => 3599,
            'refresh_token' => 'refresh-new',
            'scope' => $scope,
            'token_type' => 'Bearer',
        ]),
        'openidconnect.googleapis.com/*' => Http::response(['sub' => '1234', 'email' => $email, 'email_verified' => true]),
        'oauth2.googleapis.com/revoke' => Http::response(),
    ]);
}

/**
 * Starts the connect as $user and returns the state Google would hand back.
 */
function startConnect(User $user): string
{
    $location = (string) test()->actingAs($user)->get(route('gmail-transport.connect'))->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    return (string) $query['state'];
}

function revokeCalls(): int
{
    return Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/revoke'))->count();
}

it('lets only a signed-in user connect', function () {
    $this->get(route('gmail-transport.connect'))->assertForbidden();
    $this->get(route('gmail-transport.callback', ['state' => 'x', 'code' => 'y']))->assertForbidden();
});

it('sends the user to Google for offline access with a state', function () {
    $location = (string) $this->actingAs(user())->get(route('gmail-transport.connect'))->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect(Str::before($location, '?'))->toBe('https://accounts.google.com/o/oauth2/v2/auth')
        ->and($query)->toMatchArray([
            'client_id' => 'test-client-id',
            'redirect_uri' => 'http://localhost/gmail/callback',
            'response_type' => 'code',
            'scope' => 'openid email https://www.googleapis.com/auth/gmail.send',
            'access_type' => 'offline',
            'prompt' => 'consent',
        ])
        ->and($query['state'])->toHaveLength(40);
});

it('connects the account and keeps its tokens encrypted', function () {
    $user = user();
    googleGrants('Ana@Example.com');
    $state = startConnect($user);

    $this->get(route('gmail-transport.callback', ['state' => $state, 'code' => 'code-1']))
        ->assertRedirect('/settings')
        ->assertSessionHas('gmail-transport.status', 'connected');

    expect($user->gmailAccount)
        ->email->toBe('ana@example.com')
        ->refresh_token->toBe('refresh-new')
        ->access_token->toBe('access-new')
        ->and(DB::table('gmail_accounts')->value('refresh_token'))->not->toContain('refresh-new');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://oauth2.googleapis.com/token'
        && $request['grant_type'] === 'authorization_code'
        && $request['code'] === 'code-1'
        && $request['redirect_uri'] === 'http://localhost/gmail/callback'
        && $request['client_secret'] === 'test-client-secret');
});

it('uses the configured redirect URI for the request and the exchange', function () {
    config(['gmail-transport.redirect_uri' => 'https://app.example.com/gmail/callback']);
    $user = user();
    googleGrants();
    $state = startConnect($user);

    $this->get(route('gmail-transport.callback', ['state' => $state, 'code' => 'code-1']))->assertSessionHas('gmail-transport.status', 'connected');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://oauth2.googleapis.com/token'
        && $request['redirect_uri'] === 'https://app.example.com/gmail/callback');
});

it('refuses a wrong state', function () {
    $user = user();
    googleGrants();
    startConnect($user);

    $this->get(route('gmail-transport.callback', ['state' => str_repeat('x', 40), 'code' => 'code-1']))
        ->assertSessionHas('gmail-transport.status', 'invalid_state');

    expect(GmailAccount::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('accepts a state only once', function () {
    $user = user();
    googleGrants();
    $state = startConnect($user);

    $this->get(route('gmail-transport.callback', ['state' => $state, 'code' => 'code-1']))->assertSessionHas('gmail-transport.status', 'connected');
    $this->get(route('gmail-transport.callback', ['state' => $state, 'code' => 'code-1']))->assertSessionHas('gmail-transport.status', 'invalid_state');
});

it('refuses a state older than ten minutes', function () {
    $user = user();
    googleGrants();
    $state = startConnect($user);
    $this->travel(11)->minutes();

    $this->get(route('gmail-transport.callback', ['state' => $state, 'code' => 'code-1']))
        ->assertSessionHas('gmail-transport.status', 'invalid_state');

    Http::assertNothingSent();
});

it('refuses a callback for another user than the one who started', function () {
    googleGrants();
    $state = startConnect(user());

    $this->actingAs(user())
        ->get(route('gmail-transport.callback', ['state' => $state, 'code' => 'code-1']))
        ->assertSessionHas('gmail-transport.status', 'invalid_state');

    expect(GmailAccount::query()->count())->toBe(0);
});

it('uses up the state when the user denies the consent', function () {
    $user = user();
    googleGrants();
    $state = startConnect($user);

    $this->get(route('gmail-transport.callback', ['state' => $state, 'error' => 'access_denied']))
        ->assertSessionHas('gmail-transport.status', 'denied');
    $this->get(route('gmail-transport.callback', ['state' => $state, 'code' => 'code-1']))
        ->assertSessionHas('gmail-transport.status', 'invalid_state');

    Http::assertNothingSent();
});

it('drops a grant without the send scope, and revokes nothing', function () {
    $user = user();
    googleGrants(scope: 'openid https://www.googleapis.com/auth/userinfo.email');
    $state = startConnect($user);

    $this->get(route('gmail-transport.callback', ['state' => $state, 'code' => 'code-1']))
        ->assertSessionHas('gmail-transport.status', 'missing_scope');

    expect(GmailAccount::query()->count())->toBe(0)
        ->and(revokeCalls())->toBe(0);
});

it('refuses an address another user has connected, and revokes nothing', function () {
    $theirs = account('ana@example.com');
    $user = user();
    googleGrants('ana@example.com');
    $state = startConnect($user);

    $this->get(route('gmail-transport.callback', ['state' => $state, 'code' => 'code-1']))
        ->assertSessionHas('gmail-transport.status', 'taken');

    expect($theirs->fresh())->refresh_token->toBe('refresh-ana')
        ->and($user->gmailAccount()->exists())->toBeFalse()
        ->and(revokeCalls())->toBe(0);
});

it('renews the tokens when the user connects the same address again', function () {
    $user = user();
    $account = account('ana@example.com', owner: $user, needsReconnect: true);
    googleGrants('ana@example.com');
    $state = startConnect($user);

    $this->get(route('gmail-transport.callback', ['state' => $state, 'code' => 'code-1']))
        ->assertSessionHas('gmail-transport.status', 'connected');

    expect($account->fresh())
        ->refresh_token->toBe('refresh-new')
        ->needsReconnect()->toBeFalse()
        ->and(revokeCalls())->toBe(0);
});

it('replaces the account and revokes the old grant when the user connects another address', function () {
    $user = user();
    account('old@example.com', owner: $user);
    googleGrants('ana@example.com');
    $state = startConnect($user);

    $this->get(route('gmail-transport.callback', ['state' => $state, 'code' => 'code-1']))
        ->assertSessionHas('gmail-transport.status', 'connected');

    expect(GmailAccount::query()->pluck('email')->all())->toBe(['ana@example.com']);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/revoke') && $request['token'] === 'refresh-old');
});

it('reports a failed token exchange instead of throwing', function () {
    $user = user();
    $state = startConnect($user);
    Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'redirect_uri_mismatch', 'error_description' => 'Bad Request'], 400)]);

    $this->get(route('gmail-transport.callback', ['state' => $state, 'code' => 'code-1']))
        ->assertSessionHas('gmail-transport.status', 'failed');

    expect(GmailAccount::query()->count())->toBe(0);
});
