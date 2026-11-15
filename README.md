# Laravel Gmail Transport

[![tests](https://github.com/aljazromih/laravel-gmail-transport/actions/workflows/tests.yml/badge.svg)](https://github.com/aljazromih/laravel-gmail-transport/actions/workflows/tests.yml)

Send Laravel mail through **each user's own Gmail account**, with the Gmail API and OAuth. No SMTP
password.

- Every user connects their own Google account once. The mail then goes out from that account and
  lands in its Gmail "Sent" folder.
- The account is chosen by the message's From address, so queued mail works the same.
- Tokens are stored encrypted, refreshed when they expire, and flagged when Google stops accepting
  them.
- No Google SDK: Laravel's HTTP client and Symfony Mailer only.

## Requirements

| Laravel | PHP |
|---|---|
| 10 | 8.1 – 8.3 |
| 11 | 8.2 – 8.4 |
| 12 | 8.2 – 8.5 |
| 13 | 8.3 – 8.5 |

Laravel 10 and 11 no longer get security fixes. Composer 2.9 and later refuses to install their
releases unless the project allows it, because they have published advisories. The package itself
works on them.

## Installation

```bash
composer require aljazromih/laravel-gmail-transport
php artisan migrate
```

The migration creates the `gmail_accounts` table. Publish the config if you want to change it:

```bash
php artisan vendor:publish --tag=gmail-transport-config
```

Add the mailer to `config/mail.php`:

```php
'mailers' => [
    // …
    'gmail' => [
        'transport' => 'gmail',
    ],
],
```

Add the trait to the model that connects an account, usually `User`:

```php
use AljazRomih\GmailTransport\HasGmailAccount;

class User extends Authenticatable
{
    use HasGmailAccount;
}
```

The owner needs an integer primary key.

## Google Cloud setup

Do this once per environment. Use a separate Google Cloud project for each, because revoking a
token clears every grant of the project for that Google user.

1. Create a project at [console.cloud.google.com](https://console.cloud.google.com) and enable the
   **Gmail API**.
2. In **Google Auth Platform**:
   - **Branding**: app name, user support e-mail, developer contact.
   - **Audience**: *External*. Use *Internal* only if every user is in your Google Workspace
     organisation; a personal Gmail account can't use it.
   - **Publishing status**: *In production*. A new app starts in *Testing*, where Google's refresh
     tokens expire after seven days, and a token issued in *Testing* keeps that limit after you
     publish. Publish the app before anyone connects; whoever connected earlier connects again.
   - **Data access**: the scopes `openid`, `email` and `https://www.googleapis.com/auth/gmail.send`.
3. **Clients** → *Create client* → *Web application*. Add the authorised redirect URI,
   `https://your-app.example.com/gmail/callback`.
4. Put the client into `.env`:

```dotenv
GMAIL_CLIENT_ID=…
GMAIL_CLIENT_SECRET=…
```

`gmail.send` is a sensitive scope. Until Google verifies your app, users see "Google hasn't
verified this app" and continue under *Advanced*. An unverified app may have 100 users over its
lifetime.

## Configuration

| Key | Default | Meaning |
|---|---|---|
| `client_id`, `client_secret` | `GMAIL_CLIENT_ID`, `GMAIL_CLIENT_SECRET` | The OAuth client |
| `redirect_uri` | `GMAIL_REDIRECT_URI`, else the callback route's URL | Set it when a proxy changes the scheme or host |
| `routes.enabled` | `true` | Registers `gmail-transport.connect` and `gmail-transport.callback` |
| `routes.prefix` | `gmail` | URL prefix of both routes |
| `routes.middleware` | `['web']` | Middleware of both routes |
| `redirect_after` | `/` | A path the browser goes to after the callback |

## Connecting an account

Send a signed-in user to the connect route:

```blade
<a href="{{ route('gmail-transport.connect') }}">Connect Gmail</a>
```

Google asks for consent, then sends the user back to the callback. The callback stores the account
and redirects to `redirect_after`. The session then holds `gmail-transport.status`:

| Status | Meaning |
|---|---|
| `connected` | The account is connected, or its tokens were renewed |
| `denied` | The user declined the consent |
| `missing_scope` | The user unticked the permission to send mail |
| `taken` | Another owner has already connected this address |
| `invalid_state` | The callback didn't match a connect started by this user in the last ten minutes |
| `failed` | Google refused the code exchange; the exception is reported |

Both routes answer 403 to guests, without redirecting to a login route. Connecting another address
replaces the owner's account and revokes the old grant. A refused grant is dropped, never revoked.

To disconnect:

```php
$user->gmailAccount?->disconnect();
```

This revokes the grant at Google when it can, and deletes the account either way. Deleting the
owner does the same; soft-deleting it doesn't, until it is deleted for good.

## Sending

Send with the `gmail` mailer. The From address must be a connected account's address:

```php
Mail::mailer('gmail')
    ->to($customer)
    ->send((new InvoiceMail($invoice))->from($user->gmailAccount->email, $user->name));
```

To make it the default mailer, set `MAIL_MAILER=gmail` and a `MAIL_FROM_ADDRESS` that is connected.

How it works:

- The first address of the **From header** chooses the account, not the envelope sender (which a
  `Sender` or `Return-Path` header would change).
- The message is uploaded to the Gmail API (`users.messages.send`). Bcc recipients are written back
  into it, because Symfony leaves the Bcc header out and Gmail takes the recipients from the
  headers.
- An access token is refreshed when less than a minute is left. A 401 is retried once with a fresh
  token.

Every exception is a Symfony `TransportException`, so existing `TransportExceptionInterface`
handlers catch them:

| Exception | When |
|---|---|
| `NoGmailAccountException` | No account is connected for the From address |
| `GmailAccountNeedsReconnectException` | Google no longer accepts the account's token; connect it again |
| `GmailTransportException` | Gmail refused the message (rate limit, server error), or Google couldn't be reached |

`$account->needsReconnect()` tells your UI to ask for a new connect.

## Local development

Google accepts redirect URIs over plain http only for `localhost` and `127.0.0.1`, and only hosts
with a public top-level domain otherwise. So `http://my-app.test/gmail/callback` is refused.

Serve the app on a loopback address while you connect:

```bash
php artisan serve --host=127.0.0.1 --port=8765
```

Register `http://127.0.0.1:8765/gmail/callback` (and `http://localhost:8765/gmail/callback`) in the
OAuth client. Open the app at `http://127.0.0.1:8765`, sign in, and connect. The redirect URI
follows the host you opened, and Google matches it exactly.

## Testing your application

`Mail::fake()` works as usual. To exercise the transport itself, fake Google's endpoints:

```php
Http::fake([
    'gmail.googleapis.com/*' => Http::response(['id' => 'message-id']),
]);
```

## Troubleshooting

- **`redirect_uri_mismatch`**: the redirect URI isn't registered exactly as the app sends it
  (scheme, host, port, path). Set `GMAIL_REDIRECT_URI` behind a proxy.
- **The account needs a reconnect**: the user revoked access, changed their Google password, didn't
  send for six months, or connected while the app was in *Testing* (seven-day tokens, even after
  publishing).
- **"Google hasn't verified this app"**: expected for an unverified app with `gmail.send`.
- **Sending limits**: Gmail's own daily limits apply, about 500 recipients a day for a personal
  account.

## Security

- Tokens are encrypted with the app key. Keep `APP_KEY` when you move or restore the database.
- Exception messages carry Google's error text only, never a token or a request body.
- The connect state is single-use, expires after ten minutes, and is bound to the session and to
  the user who started it.

Report a vulnerability privately as described in [SECURITY.md](SECURITY.md).

## License

MIT. See [LICENSE.md](LICENSE.md).
