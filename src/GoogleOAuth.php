<?php

namespace AljazRomih\GmailTransport;

use AljazRomih\GmailTransport\Exceptions\GmailTransportException;
use AljazRomih\GmailTransport\Exceptions\GoogleOAuthException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Google's OAuth 2.0 endpoints for web server apps. Error messages carry Google's error text only,
 * never a token or a request body.
 */
class GoogleOAuth
{
    public const SEND_SCOPE = 'https://www.googleapis.com/auth/gmail.send';

    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

    private const USERINFO_URL = 'https://openidconnect.googleapis.com/v1/userinfo';

    public function authorizationUrl(string $state, string $redirectUri): string
    {
        return self::AUTH_URL.'?'.http_build_query([
            'client_id' => $this->clientId(),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', ['openid', 'email', self::SEND_SCOPE]),
            // A refresh token on every consent, also when the user connected before.
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @return array{access_token: string, expires_in: int, refresh_token: ?string, scope: string}
     */
    public function exchangeCode(string $code, string $redirectUri): array
    {
        return $this->token([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
        ]);
    }

    /**
     * @return array{access_token: string, expires_in: int, refresh_token: ?string, scope: string}
     */
    public function refresh(string $refreshToken): array
    {
        return $this->token([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);
    }

    public function revoke(string $token): void
    {
        $response = $this->send(fn (): Response => Http::asForm()->post(self::REVOKE_URL, ['token' => $token]));

        if (! $response->successful()) {
            throw new GoogleOAuthException((string) $response->json('error'), 'Google refused to revoke the token: '.self::describe($response));
        }
    }

    public function email(string $accessToken): string
    {
        $response = $this->send(fn (): Response => Http::withToken($accessToken)->acceptJson()->get(self::USERINFO_URL));
        $email = $response->json('email');

        if (! $response->successful() || ! is_string($email) || $email === '') {
            throw new GoogleOAuthException('userinfo', 'Google did not return the account\'s address: '.self::describe($response));
        }

        return strtolower($email);
    }

    /**
     * Google's own error text: `error_description` from the OAuth endpoints, `error.message` from
     * the Gmail API.
     */
    public static function describe(Response $response): string
    {
        $error = $response->json('error');
        $text = is_array($error) ? ($error['message'] ?? null) : ($response->json('error_description') ?? $error);

        return trim($response->status().' '.(is_string($text) ? $text : ''));
    }

    /**
     * @param  array<string, string>  $grant
     * @return array{access_token: string, expires_in: int, refresh_token: ?string, scope: string}
     */
    private function token(array $grant): array
    {
        $response = $this->send(fn (): Response => Http::asForm()->acceptJson()->post(self::TOKEN_URL, $grant + [
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
        ]));

        $accessToken = $response->json('access_token');

        if (! $response->successful() || ! is_string($accessToken) || $accessToken === '') {
            throw new GoogleOAuthException((string) $response->json('error'), 'Google refused the token request: '.self::describe($response));
        }

        $refreshToken = $response->json('refresh_token');

        return [
            'access_token' => $accessToken,
            'expires_in' => (int) $response->json('expires_in', 3600),
            'refresh_token' => is_string($refreshToken) && $refreshToken !== '' ? $refreshToken : null,
            'scope' => (string) $response->json('scope', ''),
        ];
    }

    /**
     * @param  callable(): Response  $request
     */
    private function send(callable $request): Response
    {
        try {
            return $request();
        } catch (ConnectionException $exception) {
            throw new GmailTransportException('Google could not be reached: '.$exception->getMessage());
        }
    }

    private function clientId(): string
    {
        return (string) (config('gmail-transport.client_id') ?: throw new GmailTransportException('Set GMAIL_CLIENT_ID (gmail-transport.client_id).'));
    }

    private function clientSecret(): string
    {
        return (string) (config('gmail-transport.client_secret') ?: throw new GmailTransportException('Set GMAIL_CLIENT_SECRET (gmail-transport.client_secret).'));
    }
}
