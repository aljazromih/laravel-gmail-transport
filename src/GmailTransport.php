<?php

namespace AljazRomih\GmailTransport;

use AljazRomih\GmailTransport\Exceptions\GmailAccountNeedsReconnectException;
use AljazRomih\GmailTransport\Exceptions\GmailTransportException;
use AljazRomih\GmailTransport\Exceptions\NoGmailAccountException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Header\MailboxListHeader;
use Symfony\Component\Mime\Message;

/**
 * Sends each message through the Gmail account whose address is the message's From address.
 */
class GmailTransport extends AbstractTransport
{
    private const SEND_URL = 'https://gmail.googleapis.com/upload/gmail/v1/users/me/messages/send?uploadType=media';

    protected function doSend(SentMessage $message): void
    {
        $account = $this->account($message);
        $raw = $this->bccHeader($message).$message->toString();

        $response = $this->post($account, $raw, false);

        // An access token can die before its expiry time (revoked, rotated): refresh once and retry.
        if ($response->status() === 401) {
            $response = $this->post($account, $raw, true);
        }

        if (! $response->successful()) {
            throw new GmailTransportException('Gmail refused the message: '.GoogleOAuth::describe($response));
        }

        $message->setMessageId((string) $response->json('id'));
    }

    public function __toString(): string
    {
        return 'gmail+api://default';
    }

    /**
     * The From header decides, not the envelope sender, which a Sender or Return-Path header would
     * override. A raw message has no parsed headers, so its envelope sender decides.
     */
    private function account(SentMessage $message): GmailAccount
    {
        $original = $message->getOriginalMessage();
        $from = $original instanceof Message ? $original->getHeaders()->get('From') : null;

        $address = $from instanceof MailboxListHeader && $from->getAddresses() !== []
            ? $from->getAddresses()[0]->getAddress()
            : $message->getEnvelope()->getSender()->getAddress();

        $account = GmailAccount::forAddress($address);

        if ($account === null) {
            throw new NoGmailAccountException("No Gmail account is connected for {$address}.");
        }

        if ($account->needsReconnect()) {
            throw new GmailAccountNeedsReconnectException("The Gmail account {$address} has to be connected again.");
        }

        return $account;
    }

    /**
     * Symfony drops the Bcc header from the message it renders, and Gmail takes the recipients from
     * the headers: the envelope recipients that To and Cc don't name go back in as Bcc. A raw
     * message keeps the headers its sender wrote.
     */
    private function bccHeader(SentMessage $message): string
    {
        $original = $message->getOriginalMessage();

        if (! $original instanceof Message) {
            return '';
        }

        $named = [];

        foreach (['to', 'cc'] as $name) {
            foreach ($original->getHeaders()->all($name) as $header) {
                if ($header instanceof MailboxListHeader) {
                    foreach ($header->getAddresses() as $address) {
                        $named[strtolower($address->getAddress())] = true;
                    }
                }
            }
        }

        $bcc = array_values(array_filter(
            $message->getEnvelope()->getRecipients(),
            fn (Address $recipient): bool => ! isset($named[strtolower($recipient->getAddress())]),
        ));

        return $bcc === [] ? '' : (new MailboxListHeader('Bcc', $bcc))->toString()."\r\n";
    }

    private function post(GmailAccount $account, string $raw, bool $forceRefresh): Response
    {
        $token = $account->accessToken($forceRefresh);

        try {
            return Http::withToken($token)->withBody($raw, 'message/rfc822')->post(self::SEND_URL);
        } catch (ConnectionException $exception) {
            throw new GmailTransportException('Gmail could not be reached: '.$exception->getMessage());
        }
    }
}
