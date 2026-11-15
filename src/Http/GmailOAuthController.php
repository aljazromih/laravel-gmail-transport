<?php

namespace AljazRomih\GmailTransport\Http;

use AljazRomih\GmailTransport\Exceptions\GmailTransportException;
use AljazRomih\GmailTransport\GmailAccount;
use AljazRomih\GmailTransport\GoogleOAuth;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Connects the signed-in user's Gmail account. The state is bound to the session and to the owner
 * who started, so a callback can't attach someone else's grant to another user.
 */
class GmailOAuthController
{
    public const STATUS = 'gmail-transport.status';

    private const PENDING = 'gmail-transport.pending';

    public function connect(Request $request, GoogleOAuth $google): RedirectResponse
    {
        $owner = $this->owner($request);
        $state = Str::random(40);

        $request->session()->put(self::PENDING, [
            'state' => $state,
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => (string) $owner->getKey(),
            'expires_at' => now()->addMinutes(10)->getTimestamp(),
        ]);

        return redirect()->away($google->authorizationUrl($state, $this->redirectUri()));
    }

    public function callback(Request $request, GoogleOAuth $google): RedirectResponse
    {
        // Pulled first: whatever happens next, this state can't be used again.
        $pending = $request->session()->pull(self::PENDING);
        $owner = $this->owner($request);
        $state = $request->query('state');

        if (! is_array($pending) || ! is_string($state) || ! hash_equals((string) $pending['state'], $state)
            || $pending['expires_at'] < now()->getTimestamp()
            || $pending['owner_type'] !== $owner->getMorphClass() || $pending['owner_id'] !== (string) $owner->getKey()) {
            return $this->finish('invalid_state');
        }

        $code = $request->query('code');

        if ($request->query('error') !== null || ! is_string($code) || $code === '') {
            return $this->finish('denied');
        }

        try {
            $token = $google->exchangeCode($code, $this->redirectUri());

            // Users can untick scopes on the consent screen. A refused grant is only dropped, never
            // revoked: a revoke would clear every grant of the project for that Google user.
            if (! in_array(GoogleOAuth::SEND_SCOPE, explode(' ', $token['scope']), true) || $token['refresh_token'] === null) {
                return $this->finish('missing_scope');
            }

            $email = $google->email($token['access_token']);
        } catch (GmailTransportException $exception) {
            report($exception);

            return $this->finish('failed');
        }

        $existing = GmailAccount::forAddress($email);

        if ($existing !== null && ($existing->owner_type !== $owner->getMorphClass() || (string) $existing->owner_id !== (string) $owner->getKey())) {
            return $this->finish('taken');
        }

        $current = GmailAccount::query()->where('owner_type', $owner->getMorphClass())->where('owner_id', $owner->getKey())->first();

        if ($current !== null && $current->email !== $email) {
            $current->disconnect();
            $current = null;
        }

        ($current ?? new GmailAccount)->forceFill([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getKey(),
            'email' => $email,
            'refresh_token' => $token['refresh_token'],
            'access_token' => $token['access_token'],
            'expires_at' => now()->addSeconds($token['expires_in']),
            'needs_reconnect_at' => null,
        ])->save();

        return $this->finish('connected');
    }

    private function owner(Request $request): Model
    {
        $user = $request->user();

        abort_unless($user instanceof Model, 403);

        return $user;
    }

    private function redirectUri(): string
    {
        return (string) (config('gmail-transport.redirect_uri') ?: route('gmail-transport.callback'));
    }

    private function finish(string $status): RedirectResponse
    {
        return redirect()->to((string) config('gmail-transport.redirect_after', '/'))->with(self::STATUS, $status);
    }
}
