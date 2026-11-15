<?php

namespace AljazRomih\GmailTransport;

use AljazRomih\GmailTransport\Exceptions\GmailAccountNeedsReconnectException;
use AljazRomih\GmailTransport\Exceptions\GoogleOAuthException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One owner's connected Gmail account. The tokens are encrypted with the app key.
 *
 * @property int $id
 * @property string $owner_type
 * @property int $owner_id
 * @property string $email
 * @property string $refresh_token
 * @property ?string $access_token
 * @property ?Carbon $expires_at
 * @property ?Carbon $needs_reconnect_at
 */
class GmailAccount extends Model
{
    protected $table = 'gmail_accounts';

    protected $guarded = ['id'];

    protected $hidden = ['refresh_token', 'access_token'];

    // A property, not casts(): Laravel 10 has no casts() method.
    protected $casts = [
        'refresh_token' => 'encrypted',
        'access_token' => 'encrypted',
        'expires_at' => 'datetime',
        'needs_reconnect_at' => 'datetime',
    ];

    public static function forAddress(string $address): ?self
    {
        return static::query()->where('email', strtolower($address))->first();
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    public function needsReconnect(): bool
    {
        return $this->needs_reconnect_at !== null;
    }

    /**
     * A valid access token, refreshed when less than a minute is left or when forced.
     */
    public function accessToken(bool $forceRefresh = false): string
    {
        if (! $forceRefresh && $this->access_token !== null && $this->expires_at?->isAfter(now()->addMinute())) {
            return $this->access_token;
        }

        try {
            $token = app(GoogleOAuth::class)->refresh($this->refresh_token);
        } catch (GoogleOAuthException $exception) {
            if ($exception->error === 'invalid_grant') {
                $this->forceFill(['needs_reconnect_at' => now()])->save();

                throw new GmailAccountNeedsReconnectException("The Gmail account {$this->email} has to be connected again: Google no longer accepts its token.");
            }

            throw $exception;
        }

        $this->forceFill([
            'access_token' => $token['access_token'],
            'expires_at' => now()->addSeconds($token['expires_in']),
        ])->save();

        return $token['access_token'];
    }

    /**
     * Revokes the grant at Google when it can, and forgets the account either way.
     */
    public function disconnect(): void
    {
        rescue(fn () => app(GoogleOAuth::class)->revoke($this->refresh_token), report: false);

        $this->delete();
    }
}
