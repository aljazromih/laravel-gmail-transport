# Changelog

All notable changes are listed here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow
[Semantic Versioning](https://semver.org/).

## Unreleased

- Gmail API mail transport: each message goes out through the connected account of its From
  address.
- OAuth connect and callback routes, with a single-use state bound to the session and the user.
- Encrypted token storage, refresh before expiry, one retry on a 401, and a reconnect flag when
  Google no longer accepts a token.
- `HasGmailAccount` trait; deleting the owner disconnects its account.
- Laravel 10 to 13, PHP 8.1 to 8.5.
