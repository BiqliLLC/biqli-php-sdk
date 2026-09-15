<?php

declare(strict_types=1);

use Biqli\Sdk\Biqli;

$biqli = new Biqli(
    apiKey: (string) config('services.biqli.api_key'),
    options: [
        'clientName' => 'acme-laravel',
        'clientVersion' => '1.0.0',
    ],
);

$customer = auth()->user();

$lead = $biqli->track->lead([
    'clickId' => request()->cookie('bq_id'),
    'eventId' => 'signup:'.$customer->getKey(),
    'eventName' => 'Signed up',
    'customerExternalId' => (string) $customer->getKey(),
    'customerName' => $customer->name,
    'customerEmail' => $customer->email,
]);

$sale = $biqli->track->sale([
    'customerExternalId' => (string) $customer->getKey(),
    'eventName' => 'Purchase',
    'amount' => 4999,
    'currency' => 'usd',
    'paymentProcessor' => 'stripe',
    'invoiceId' => 'invoice_123',
]);
