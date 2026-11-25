# TurboSMS

A small SMS and Viber client for the [TurboSMS HTTP API](https://turbosms.ua/en/api.html).

[Докладний посібник українською: режими, таймери, приклади та помилки](docs/USAGE.uk.md).
[Вебхуки українською: підпис, endpoint, повтори та збереження подій](docs/WEBHOOKS.uk.md).
[Логування PSR-3 українською: підключення, поля та захист даних](docs/LOGGING.uk.md).

## Installation

Requires PHP 7.2.5+ and the JSON and Hash extensions. PHP 7 compatibility is retained for
existing applications; use a currently supported PHP release for new deployments.

Add the repository and package to your application's `composer.json`:

```json
{
    "repositories": [
        {"type": "git", "url": "https://github.com/Edrard/TurboSMS.git"}
    ],
    "require": {"edrard/turbosms": "^1.0"}
}
```

Then run `composer update edrard/turbosms --with-all-dependencies`.

Stable releases are tagged `v1.x.x` on `main`; applications should use `^1.0`
instead of the moving `dev-main` branch. The repository configuration above also
works when the package is not registered on Packagist. See [CHANGELOG](CHANGELOG.md).

### Guzzle compatibility

The library accepts `guzzlehttp/guzzle: ^7.17 || ^8.6`:

| Application environment | Compatible dependency |
| --- | --- |
| PHP 7.2.5–7.3 | Guzzle 7.17+ |
| PHP 7.4+ | Guzzle 7.17+ or 8.6+, depending on application constraints |

Existing `Api`, `Message`, `Webhook` calls and result shapes are unchanged.
Composer respects other packages that still require Guzzle 7; permitting Guzzle
8 does not force all existing applications to upgrade their HTTP client.
Guzzle 8.5.1 is outside the supported range; use the maintained 8.6+ line.

The local library checkout pins `config.platform.php` to 7.2.5 and therefore
resolves Guzzle 7 by default. To test Guzzle 8 in a disposable checkout, run:

```bash
composer config platform.php 8.5.0
composer update --with "guzzlehttp/guzzle:^8.6" --with-all-dependencies
composer check-platform-reqs
composer test
```

Use an actual PHP 8.5 runtime for those commands; the Composer platform setting
does not install PHP. In consuming applications set the platform to their real
deployment target. Do not modify production lock files just to run this example.

For a checkout of this library, run `composer update` to resolve dependencies and
create a local lock file. `composer.lock` and `vendor/` remain ignored for this
library; applications should commit their own lock file. The development platform
is pinned to PHP 7.2.5 to keep locally resolved dependencies compatible with the
minimum PHP version. This Composer setting does not override consuming applications.

## Existing usage

The constructor, fluent setters and `resp` / `error` result keys are preserved:

```php
use edrard\Turbosms\Api;
use edrard\Turbosms\Message;

$api = new Api([
    'url' => 'https://api.turbosms.ua/',
    'token' => getenv('TURBOSMS_TOKEN'),
]);

$message = new Message();
$message->setRecipients(['380000000000'])
    ->setFrom('Company_name')
    ->setText('Hello');

try {
    $result = $api->sendMessage($message);
    // $result['resp']: original TurboSMS response, including message IDs.
    // $result['error']: rejected recipient rows, with their original indices.
    // 800/801 mean accepted/sent, not confirmed delivery to the phone.
} catch (\Exception $e) {
    // Handle the failure. Do not retry blindly or log credentials/message data.
}
```

HTTP API access must be enabled in your TurboSMS account. Each configured sender
must be activated for its channel, and sufficient funds and destination permissions
are required.

## Viber and hybrid delivery

`Message` uses SMS by default. Select `viber` for Viber only, or `hybrid` for
Viber first with automatic SMS fallback managed by TurboSMS:

```php
// Viber only: try delivery for up to 120 seconds.
$viber = (new Message(['380000000000'], 'Viber Sender', 'Hello on Viber'))
    ->setChannel('viber')
    ->setViberTtl(120);
$result = $api->sendMessage($viber);

// Hybrid: try Viber for 60 seconds, then allow SMS if Viber is undelivered.
$hybrid = (new Message(['380000000000'], 'SMSAlpha', 'Your order is ready'))
    ->setChannel('hybrid')
    ->setViberFrom('Viber Sender')
    ->setViberText('Your order is ready. Thank you for shopping with us!')
    ->setViberTtl(60)
    ->setSequenceId('order-123-ready');
// Optional: do not create fallback SMS after the hybrid message is 300 seconds old.
// $hybrid->setHybridTtl(300);
$result = $api->sendMessage($hybrid);
```

Configure all options before the initial send. Changing the payload and reusing
an already submitted `sequence_id` returns the previous request result instead
of applying the change.

`setFrom()` and `setText()` supply shared defaults. Use `setViberFrom()` /
`setViberText()` and `setSmsFrom()` / `setSmsText()` to override each channel.
Passing `null` to an override removes it and restores the current shared default.
The constructor and `setRecipients()` still define one common recipient list.
`setSequenceId()` applies to the whole request, including both hybrid channels.

| Setting | Meaning | Valid values / default |
| --- | --- | --- |
| `setChannel()` | Select delivery mode | `sms` (default), `viber`, `hybrid` |
| `setViberTtl()` | How long TurboSMS tries to deliver the Viber message | Integer 30–86400 seconds; omitted means API default 86400 |
| `setHybridTtl()` | Deadline after which fallback SMS will no longer be created | Positive integer seconds; omitted means no explicit hybrid deadline |

**The timers have different meanings.** To wait 60 seconds for Viber and then
allow SMS, set only `setViberTtl(60)`. Viber can be rejected sooner, in which case
the fallback can occur sooner. Expiration and fallback timing are handled by
TurboSMS, not by a local sleep or a second request from this client.

If both timers are 60, SMS may be suppressed after the Viber wait because the
hybrid deadline has also elapsed. This configuration is useful for expiring
messages when SMS is wanted only after an early Viber rejection. Set a later
hybrid deadline or omit it when SMS should remain available after the Viber wait.
These rules follow the [TurboSMS ttl / hybrid_ttl documentation](https://turbosms.ua/en/api.html).

`setViberTtl(null)` restores the API default; `setHybridTtl(null)` removes the
hybrid deadline. Timing strings such as `'60'`, floats and booleans are rejected.
Viber options require `viber` or `hybrid`; SMS overrides require `sms` or `hybrid`;
`hybrid_ttl` requires `hybrid`. Inactive channel options are rejected rather than
silently ignored. Clear them with `null` before switching to an incompatible mode.

Hybrid delivery uses one API request containing `viber` and `sms` blocks. The
return shape remains `['resp' => ..., 'error' => ...]` for every mode. Acceptance
of that request does not confirm Viber delivery or that fallback SMS was sent.

## Viber images

Add an image URL to a Viber or hybrid message:

```php
$message = (new Message(['380000000000'], 'SMSAlpha', 'Your order is ready'))
    ->setChannel('hybrid')
    ->setViberFrom('Viber Sender')
    ->setViberText('Your order is ready. Here is a photo.')
    ->setViberImageUrl('https://cdn.example.org/orders/123.jpg')
    ->setViberTtl(60)
    ->setSequenceId('order-123-photo');
$result = $api->sendMessage($message);
```

The URL becomes `viber.image_url` only; the SMS fallback retains its own text
and sender. It must be an absolute HTTP(S) URL without credentials, fragments,
whitespace or control characters. Prefer HTTPS. Query parameters and image URLs
without filename extensions are allowed. Use a URL that TurboSMS can fetch
without your application's cookies, login session or custom headers, and keep it
available while delivery is pending. The client does not download or inspect the
image; image format, accessibility and service limits are checked by TurboSMS.

Alternatively, reference an image already uploaded to TurboSMS:

```php
$message->setViberImageFileId(12345);
```

This sends `viber.file_id`, a positive integer. The ID must refer to an image in
your TurboSMS account. Uploading via `file/add` is not implemented by this client;
obtain the ID through your existing upload integration. It does not send a local
file path, generic document attachment or inline Base64 image.
Select the image source before the first send; changing it does not update a
request already executed under the same `sequence_id`.

The latest non-null image setter selects the source and clears the other source.
`setViberImageUrl(null)` removes only the URL; `setViberImageFileId(null)` removes
only the file ID. Changing or clearing the source does not restore an earlier
source automatically. Clear image options before switching to `sms` mode.

Image-only Viber messages are also supported:

```php
$imageOnly = (new Message(['380000000000'], 'Viber Sender'))
    ->setChannel('viber')
    ->setViberImageUrl('https://cdn.example.org/photo.jpg');
$result = $api->sendMessage($imageOnly);
```

An exact empty Viber text (`''`) is omitted from the outgoing image payload.
For image-only Viber with hybrid fallback, call `setViberText('')` while keeping
the SMS text non-empty. Removing the image makes empty Viber text invalid again.
Non-empty Viber text still has the 1000-character limit. Invalid images can cause
API rejection before sending; hybrid mode does not turn every rejected API call
into a separate SMS request. Existing error handling and deduplication rules apply.

## Delivery status

Pass the message IDs returned by a successful send to `getMessageStatus()`:

```php
$statusResult = $api->getMessageStatus([
    'f83f8868-5e46-c6cf-e4fb-615e5a293754',
    'c51f4301-5e3c-78c9-134b-d1ce1e56a9ff',
]);

foreach ($statusResult['resp']['response_result'] as $row) {
    if ($row['response_code'] !== 0) {
        continue; // Also available in $statusResult['error'], at the original index.
    }
    $messageId = $row['message_id'];
    $state = $row['status'];
    $channel = $row['channel']; // sms or viber; do not depend on deprecated type.
    $sentAt = $row['sent'];    // Can be null while queued.
    $updatedAt = $row['updated'];
}
```

The method sends `{"messages":[...]}` to `message/status.json`. Supply a
non-empty array of UUID-shaped strings; sparse arrays become JSON lists. Do not
pass phone numbers, null message IDs from rejected sends, or `sequence_id` values.
No undocumented batch-size limit is imposed by this client.

The result uses the same `resp` / `error` keys as `sendMessage()`. A row with API
code `414`, for example, is an ID lookup failure in `error`. A successfully read
row with delivery status `Failed`, `Expired`, `Rejected` or `Undelivered` remains
in `resp` and is not an API lookup error. Inspect `status` explicitly.

`Delivered` and `Read` indicate successful delivery/read states. `Queued`,
`Accepted` and `Sent` can still be in progress. Other returned values, extra
fields and server timestamps are preserved, including Viber `rejected_status`
and optional `click_time`; unknown future status names are not discarded.
For hybrid sends, inspect the returned `channel` rather than assuming SMS was used.

This is a single status snapshot, not an automatic poller. For push delivery
reports, use the separate `Webhook` handler described below.
Store message IDs after sending and call again according to your application's
schedule while tracking in-progress messages. Reading status does not resend them.

## Account balance

```php
$balanceResult = $api->getBalance();
$balance = $balanceResult['resp']['response_result']['balance'];
```

`getBalance()` posts an empty body to `user/balance.json` and returns
`['resp' => $originalApiResponse, 'error' => []]`. Balance is retained as the
decoded JSON number (`int` or `float`), with no additional rounding or currency
conversion. Zero and negative numeric balances are accepted; missing, non-numeric
or non-finite data cause `InvalidResponseException`, not a fabricated zero balance.
The API response does not provide a currency field, so the client does not add one.

Both read methods use a 30-second HTTP timeout, bearer authentication, disabled
redirects and no automatic retries. They do not require `sequence_id` and cannot
create a message. Failed reads can be retried by the caller without sending a new
message. Top-level API rejections throw `ApiException`; transport and malformed
responses use the existing `TransportException` / `InvalidResponseException`.
The send path retains its previous no-total-timeout behavior.

## Delivery webhooks

`edrard\Turbosms\Webhook` handles `DLR_SMS_API` and `DLR_VIBER_API` events
without an outbound API call. Configure a dedicated webhook secret and an HTTPS
callback in your TurboSMS account, selecting these event types. Prefer JSON;
URL-encoded and PHP-parsed multipart FORM DATA are also supported.

```php
use edrard\Turbosms\Webhook;

$webhook = new Webhook(getenv('TURBOSMS_WEBHOOK_SECRET'));
$event = $webhook->parse($rawBody, 'application/json');
// Multipart alternative: $event = $webhook->parseForm($_POST);

foreach ($event['deliveries'] as $delivery) {
    $messageId = $delivery['message_id'];
    $channel = $delivery['channel'];
    $state = $delivery['status'];
}
```

The parser verifies `SHA1(secret + event ID)` with `hash_equals()`, validates
the whole event and maps provider codes to API-style states: `DELIVRD` →
`Delivered`, `READ` → `Read`, `EXPIRED` → `Expired`, `UNDELIV` → `Undelivered`,
`REJECTD` → `Rejected`. Unknown uppercase codes become `Unknown`, retaining
`raw_status`. Original row data and error-code formatting are preserved.

The normalized envelope contains `id`, `type`, `date`, `try`, and `deliveries`;
it does not use `resp` / `error`. Each delivery has `message_id`, `channel`,
`status`, `raw_status`, `sent`, `updated`, `error_code`, and original `data`.
SMS events imply channel `sms`; Viber API events carry the actual `sms` or
`viber` channel, including hybrid fallback. Callback dates remain Kyiv-time strings.

This provider signature covers the event ID, not the complete body. Use HTTPS
and consider the provider's additional HTTP Basic authentication. Replays must
be handled by the application: persist once per event `id`, not per message ID,
because delivered/read updates may be distinct events for the same message.
Do not assume callback arrival order. Retain channel and report time when
updating application state; reconcile uncertain state with `getMessageStatus()`.

`handle($rawBody, $consumer, $contentType)` parses, invokes your consumer once
for the complete event, and returns `OK` only after that consumer succeeds.
Consumer exceptions propagate. The application must return HTTP **200** after
durable storage; other HTTP codes cause provider retries. The parser does not
emit headers, acknowledge automatically, or persist events by itself.

The default body limit is 1 MiB, configurable through the constructor's second
argument. Oversized/invalid input raises `InvalidWebhookException`; wrong
signatures raise `WebhookAuthenticationException`; other event types raise
`UnsupportedWebhookException` (a subclass of `InvalidWebhookException`). Forms
near PHP's input variable limit are rejected to avoid partial processing.

See [the working SQLite inbox endpoint](docs/webhook-endpoint.php) and
[the detailed webhook guide](docs/WEBHOOKS.uk.md) for environment configuration,
Basic authentication, deduplication and optional loopback HTTP integration tests.
The endpoint stores events atomically before acknowledgment; your application
worker applies them to its own message records. No public endpoint is deployed
or account webhook enabled by installing the package.

## Request deduplication

```php
$message->setSequenceId('invoice-123-notification-1');
$result = $api->sendMessage($message);
```

`sequence_id` is optional and is sent at the top level of the JSON request.
Choose one stable ID per logical send operation and persist it before sending.
Reuse that ID and the same payload after an uncertain network outcome, including
after a process restart. Use a new ID for a genuinely new message. The API keeps
IDs for 30 days, separately for each method. The library never retries automatically
and never creates a new ID for you. `setSequenceId(null)` removes the parameter.

A repeated request returns API code `507`. The client throws
`DuplicateRequestException`, whose `getResponse()` contains the returned previous
result. It does not assume that every recipient in that previous result succeeded.
Inspect and reconcile the stored result; do not send again with a fresh ID just
because `507` was returned.

```php
use edrard\Turbosms\Exception\DuplicateRequestException;
use edrard\Turbosms\Exception\TransportException;

try {
    $result = $api->sendMessage($message);
} catch (DuplicateRequestException $e) {
    $previousResponse = $e->getResponse();
    // Reconcile response_result and previously stored message IDs.
} catch (TransportException $e) {
    // Delivery is unknown; retain this message's original sequence_id.
}
```

## Validation and errors

Configuration is checked when constructing `Api` or calling `changeConfig()`.
The URL must use HTTPS and contain no credentials, query or fragment; the token
must be a non-empty string without whitespace or control characters. A failed
`changeConfig()` leaves the previous configuration intact.

Messages are checked at send time, so constructing an empty `Message` and filling
it with setters still works. Validation checks:

- Non-empty UTF-8 sender, at most 25 characters, without CR/LF or NUL.
- UTF-8 text, at most 1521 characters for SMS or 1000 for Viber, without NUL.
  Text is required except for image-only Viber. Each active channel's resolved
  sender and content are validated.
- Between 1 and 5000 international phone numbers: 7–15 digits without `+`.
  Integer phone numbers remain supported and are serialized as strings.
- Optional non-empty UTF-8 `sequence_id`, at most 40 characters, without controls.

Carrier-specific sender rules, encoding/segment limits (including the documented
661-character Cyrillic limit), balance and permissions are validated by TurboSMS.
Sparse recipient arrays are converted to JSON lists without modifying `getConfig()`.

All exceptions are compatible with an existing `catch (\Exception $e)`:

| Exception | Meaning |
| --- | --- |
| `\InvalidArgumentException` | Invalid local configuration, message or status ID list; the requested API operation is not made. |
| `Exception\ApiException` | API rejection; `getCode()` and `getResponse()` expose structured details. |
| `Exception\DuplicateRequestException` | API code 507; extends `ApiException`, retains the previous result. |
| `Exception\InvalidResponseException` | Malformed JSON/envelope/operation result; a failed send may already have delivered. |
| `Exception\TransportException` | Network/stream failure or non-200 HTTP status; a failed send may already have delivered. |

Exception classes are in `edrard\Turbosms\Exception`. Messages omit raw request
headers, response bodies and underlying transport diagnostics. Structured API
responses can contain recipient data; handle them accordingly.

The send request has no total HTTP timeout, following TurboSMS guidance.
Redirects are disabled. Infrastructure or process timeouts can still interrupt a
request; an interrupted response does not establish that sending failed.

## PSR-3 logging

`Api` and `Webhook` accept any `Psr\Log\LoggerInterface` and implement
`LoggerAwareInterface`. Existing constructors still work and use `NullLogger`
by default. The library does not create log files or select a logging backend.

```php
// $logger is your application's configured PSR-3 logger, for example Monolog.
$api = new Api($config, null, $logger); // Second argument is still the HTTP client.
$api->setLogger($logger);              // Standard alternative after construction.

$webhook = new \edrard\Turbosms\Webhook(getenv('TURBOSMS_WEBHOOK_SECRET'), 1048576, $logger);
```

Operations log a `debug` start and a single completion/failure: `info` for
success, `warning` for partial API failures or invalid input/authentication,
`notice` for API duplicate code 507, and `error` for other operation failures.
Safe context includes the operation, a locally generated correlation `request_id`,
duration in milliseconds, known HTTP/API codes, and error/delivery counts.

Tokens, webhook secrets/signatures, message text, recipients, senders, image/API
URLs, balance, provider IDs, `sequence_id`, raw responses and exception objects
are never passed by the SDK to the logger. Logger failures do not change the
operation result or replace its original exception. `webhook.parse` means parsed;
`webhook.handle` completes only after the consumer succeeds.

`psr/log` 1.x, 2.x and 3.x are supported, allowing existing PSR-3 backends without
requiring Monolog itself. PSR-4 autoloading remains unchanged.
See [the detailed logging guide](docs/LOGGING.uk.md) for configuration and levels.

## Scope

The library supports text SMS, text/image Viber messages and hybrid Viber-to-SMS
delivery, delivery-status queries and account balance queries. Viber uses the
API's default promotional message type. HTTP API delivery webhooks are supported.
Viber buttons, document attachments, carousels, surveys, file uploads,
transactional patterns, incoming-message/click webhooks and other API modules
are not implemented.
The optional second `sendMessage()` argument remains a relative API method path;
changing it does not select a channel or enable another API module.

## Local examples

`examples/` is ignored by Git. The local `examples/exm.php` offers CLI preview,
explicit sending, status lookup and balance commands. Preview makes no HTTP
request; sending requires environment configuration and an explicit `send` command.
Keep tokens in environment variables, not in the example source. Local example
files are not included in a clean clone. Reusable code examples remain in this
README and the guides. The public webhook endpoint source is
[docs/webhook-endpoint.php](docs/webhook-endpoint.php); copy it to
`examples/webhook.php` before running the optional endpoint integration test.

## Tests

```bash
composer validate --strict
composer check-platform-reqs
composer audit
composer test
```

Tests use Guzzle's `MockHandler`; they send no real SMS and require no API token.
Webhook unit tests also run offline; the optional `tests/webhook-endpoint.php`
integration test uses a local server and PDO SQLite (see the webhook guide).
An optional second constructor argument accepts a `GuzzleHttp\ClientInterface`
for tests or integration. An injected client must not add automatic retries.
GitHub Actions checks PHP 7.2, 7.4 and 8.2–8.6. Legacy versions are included to
verify compatibility, not as a recommendation for production use.
The matrix explicitly resolves both Guzzle 7 and 8 wherever supported (Guzzle 8
requires PHP 7.4+), in addition to PSR logging compatibility checks.

## License

MIT.
