<?php

use AljazRomih\GmailTransport\GmailAccount;
use AljazRomih\GmailTransport\Tests\Fixtures\User;
use AljazRomih\GmailTransport\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(TestCase::class, RefreshDatabase::class)->in(__DIR__);

uses()->beforeEach(fn () => Http::preventStrayRequests())->in(__DIR__);

function user(?string $email = null): User
{
    return User::query()->create([
        'name' => 'Test user',
        'email' => $email ?? Str::random(10).'@example.com',
        'password' => 'not-used',
    ]);
}

/**
 * A connected account with tokens named after its address ("refresh-ana", "access-ana").
 */
function account(string $email, ?Model $owner = null, ?Carbon $expiresAt = null, bool $needsReconnect = false): GmailAccount
{
    $owner ??= user();
    $name = Str::before($email, '@');

    return GmailAccount::query()->forceCreate([
        'owner_type' => $owner->getMorphClass(),
        'owner_id' => $owner->getKey(),
        'email' => $email,
        'refresh_token' => "refresh-{$name}",
        'access_token' => "access-{$name}",
        'expires_at' => $expiresAt ?? now()->addHour(),
        'needs_reconnect_at' => $needsReconnect ? now() : null,
    ]);
}
