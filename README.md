# biqli/sdk

Server-only PHP SDK for Biqli conversion tracking. It requires PHP 8.1 or newer and a secret workspace API key with the `conversions.create` permission.

```bash
composer require biqli/sdk
```

```php
use Biqli\Sdk\Biqli;

$biqli = new Biqli($_ENV['BIQLI_API_KEY']);

$biqli->track->lead([
    'clickId' => $_COOKIE['bq_id'] ?? null,
    'eventId' => 'signup:customer_123',
    'eventName' => 'Signed up',
    'customerExternalId' => 'customer_123',
]);

$biqli->track->sale([
    'customerExternalId' => 'customer_123',
    'amount' => 4999,
    'currency' => 'usd',
    'paymentProcessor' => 'stripe',
    'invoiceId' => 'invoice_123',
]);
```

Amounts use the currency's integer minor unit. Never expose `BIQLI_API_KEY` in HTML, JavaScript, URLs, logs, or browser storage.

## Configuration

The optional second constructor argument accepts:

- `baseUrl`: API base URL, defaulting to `https://biq.li/api`.
- `timeoutMs`: timeout per attempt, defaulting to 10 seconds.
- `clientName` and `clientVersion`: values recorded in Biqli diagnostics.
- `retry`: `maxRetries`, `baseDelayMs`, `maxDelayMs`, and `jitter` settings.
- `httpClient`: a custom Guzzle-compatible client for testing or framework integration.

Every call receives a stable event ID, idempotency key, and request ID before its first attempt. Provide an `eventId` for durable lead replay. For sales, `paymentProcessor` plus `invoiceId` derives a deterministic event ID, so retrying the same invoice across processes remains safe.

`BiqliException` provides `errorCode`, `status`, `requestId`, `details`, `retryable`, and `attempts`.

See `examples/laravel.php` for a Laravel integration.
