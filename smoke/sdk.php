<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use Biqli\Sdk\Biqli;
use Biqli\Sdk\Exceptions\BiqliException;
use Biqli\Sdk\Version;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

function ensure(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$history = [];
$mock = new MockHandler([
    new ConnectException('Temporary network failure', new Request('POST', 'https://example.test')),
    new Response(201, ['Content-Type' => 'application/json', 'X-Biq-Request-Id' => 'php-smoke'], json_encode([
        'event' => ['id' => 'biq_lev_smoke', 'eventName' => 'Signed up', 'type' => 'lead'],
        'click' => ['id' => 'click_smoke'],
        'link' => null,
        'customer' => ['id' => 'biq_cus_smoke', 'externalId' => 'customer-1'],
        'request_id' => 'php-smoke',
    ], JSON_THROW_ON_ERROR)),
]);
$stack = HandlerStack::create($mock);
$stack->push(Middleware::history($history));

$biqli = new Biqli('biqli_php_sdk_smoke', [
    'baseUrl' => 'https://example.test/api',
    'clientName' => 'php-sdk-smoke',
    'clientVersion' => Version::SDK_VERSION,
    'retry' => ['maxRetries' => 2, 'baseDelayMs' => 0, 'maxDelayMs' => 0, 'jitter' => false],
    'httpClient' => new Client(['handler' => $stack]),
]);

$response = $biqli->track->lead([
    'clickId' => 'click_smoke',
    'eventName' => 'Signed up',
    'customerExternalId' => 'customer-1',
]);

ensure($response['event']['id'] === 'biq_lev_smoke', 'Typed lead response failed.');
ensure(count($history) === 2, 'Retry count failed.');
ensure(
    $history[0]['request']->getHeaderLine('Idempotency-Key') === $history[1]['request']->getHeaderLine('Idempotency-Key'),
    'Retry idempotency key changed.',
);
ensure(
    $history[0]['request']->getHeaderLine('X-Biq-Request-Id') === $history[1]['request']->getHeaderLine('X-Biq-Request-Id'),
    'Retry request ID changed.',
);
ensure((string) $history[0]['request']->getBody() === (string) $history[1]['request']->getBody(), 'Retry body changed.');

try {
    new Biqli('biqli_pk_browser_key');
    throw new RuntimeException('Publishable key was accepted.');
} catch (BiqliException $exception) {
    ensure($exception->errorCode === 'invalid_api_key', 'Wrong invalid-key error code.');
}

echo json_encode([
    'version' => Version::SDK_VERSION,
    'typedLead' => 'passed',
    'stableRetry' => 'passed',
    'structuredError' => 'passed',
    'publishableKeyGuard' => 'passed',
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
