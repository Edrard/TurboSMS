# Changelog

## 1.0.0 — 2026-11-25

First stable release of the TurboSMS HTTP API client.

- Supports Guzzle `^7.17 || ^8.6` with one compatible public API. PHP 7.2.5 is
  retained with Guzzle 7; Guzzle 8 is available on PHP 7.4+.
- Sends SMS, Viber and hybrid Viber-to-SMS messages with separate channel text,
  senders and delivery lifetimes, plus Viber images by URL or existing file ID.
- Provides delivery-status and balance queries, request deduplication IDs,
  input validation and structured exceptions.
- Parses signed HTTP API delivery webhooks in JSON/form formats and includes
  a durable SQLite inbox endpoint example.
- Integrates optional PSR-3 logging without passing message content or credentials
  to the logger. Supports psr/log 1.x, 2.x and 3.x.
- Validates webhook timestamps without depending on named timezone aliases.
- Adds CI coverage for both Guzzle generations and regression tests covering
  existing constructors, fluent setters and `resp` / `error` result keys.

Upgrade applications from `dev-main` to `^1.0` after configuring the Git
repository in Composer. No public call-site changes are required for previously
supported SMS, Viber, status, balance or webhook operations.
