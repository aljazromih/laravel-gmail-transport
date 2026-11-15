<?php

use AljazRomih\GmailTransport\GmailAccount;
use AljazRomih\GmailTransport\Tests\Fixtures\Team;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('revokes the grant and forgets the account on disconnect', function () {
    $account = account('ana@example.com');
    Http::fake(['oauth2.googleapis.com/revoke' => Http::response()]);

    $account->disconnect();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://oauth2.googleapis.com/revoke' && $request['token'] === 'refresh-ana');
    expect(GmailAccount::query()->count())->toBe(0);
});

it('forgets the account even when Google can\'t revoke', function () {
    $account = account('ana@example.com');
    Http::fake(['oauth2.googleapis.com/revoke' => Http::response(['error' => 'invalid_token'], 400)]);

    $account->disconnect();

    expect(GmailAccount::query()->count())->toBe(0);
});

it('disconnects when its owner is deleted', function () {
    $user = user();
    account('ana@example.com', owner: $user);
    Http::fake(['oauth2.googleapis.com/revoke' => Http::response()]);

    $user->delete();

    expect(GmailAccount::query()->count())->toBe(0);
    Http::assertSent(fn (Request $request): bool => $request['token'] === 'refresh-ana');
});

it('keeps the account of a soft-deleted owner until it is deleted for good', function () {
    $team = Team::query()->create(['name' => 'Team']);
    account('team@example.com', owner: $team);
    Http::fake(['oauth2.googleapis.com/revoke' => Http::response()]);

    $team->delete();

    expect(GmailAccount::query()->count())->toBe(1);
    Http::assertNothingSent();

    $team->forceDelete();

    expect(GmailAccount::query()->count())->toBe(0);
});

it('hides the tokens from arrays and JSON', function () {
    expect(account('ana@example.com')->toArray())->not->toHaveKeys(['refresh_token', 'access_token']);
});
