<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use Biqli\Sdk\Biqli;
use Biqli\Sdk\Version;

$apiKey = trim((string) getenv('BIQLI_API_KEY'));
$clickId = trim((string) getenv('BIQLI_CLICK_ID'));
if ($apiKey === '' || $clickId === '') {
    fwrite(STDERR, "Set BIQLI_API_KEY and BIQLI_CLICK_ID before running this smoke test.\n");
    exit(1);
}

$biqli = new Biqli($apiKey, [
    'clientName' => 'biqli-php-sdk-live-smoke',
    'clientVersion' => Version::SDK_VERSION,
]);
$id = 'php-sdk-live-'.(string) ((int) floor(microtime(true) * 1000));

$leadInput = [
    'clickId' => $clickId,
    'eventId' => $id.'-lead',
    'eventName' => 'PHP SDK live smoke',
    'customerExternalId' => $id,
    'customerName' => 'PHP SDK Customer',
];
$firstLead = $biqli->track->lead($leadInput, ['requestId' => $id.'-lead-request']);
$replayedLead = $biqli->track->lead($leadInput, ['requestId' => $id.'-lead-replay']);

$saleInput = [
    'customerExternalId' => $id,
    'eventName' => 'PHP SDK purchase',
    'amount' => 1299,
    'currency' => 'usd',
    'paymentProcessor' => 'custom',
    'invoiceId' => $id.'-invoice',
];
$firstSale = $biqli->track->sale($saleInput, ['requestId' => $id.'-sale-request']);
$replayedSale = $biqli->track->sale($saleInput, ['requestId' => $id.'-sale-replay']);

$result = [
    'customerExternalId' => $id,
    'leadEventId' => $firstLead['event']['id'] ?? null,
    'leadReplaySame' => ($firstLead['event']['id'] ?? null) === ($replayedLead['event']['id'] ?? null),
    'saleEventId' => $firstSale['event']['id'] ?? null,
    'saleReplaySame' => ($firstSale['event']['id'] ?? null) === ($replayedSale['event']['id'] ?? null),
    'saleAmount' => $firstSale['sale']['amount'] ?? null,
    'saleCurrency' => $firstSale['sale']['currency'] ?? null,
    'leadClick' => $firstLead['click']['id'] ?? null,
    'saleClick' => $firstSale['click']['id'] ?? null,
];

if (
    !$result['leadReplaySame']
    || !$result['saleReplaySame']
    || $result['saleAmount'] !== 1299
    || $result['saleCurrency'] !== 'usd'
    || $result['leadClick'] !== $clickId
    || $result['saleClick'] !== $clickId
) {
    throw new RuntimeException('Live PHP SDK smoke assertions failed: '.json_encode($result, JSON_THROW_ON_ERROR));
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
