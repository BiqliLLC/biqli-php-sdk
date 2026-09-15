<?php

declare(strict_types=1);

namespace Biqli\Sdk;

use Biqli\Sdk\Internal\HttpClient;

final class Tracking
{
    public function __construct(private readonly HttpClient $client)
    {
    }

    /**
     * @param array{
     *   clickId?: string,
     *   eventId?: string,
     *   eventName: string,
     *   customerExternalId: string,
     *   customerName?: string,
     *   customerEmail?: string,
     *   customerAvatar?: string,
     *   occurredAt?: string,
     *   metadata?: array<string, mixed>
     * } $input
     * @param array{idempotencyKey?: string, requestId?: string} $options
     * @return array<string, mixed>
     */
    public function lead(array $input, array $options = []): array
    {
        $input['eventId'] = $this->optionalId($input['eventId'] ?? null) ?? $this->client->uuid('evt');

        return $this->client->post('v1/track/lead', $input, $options);
    }

    /**
     * Amounts use the currency's integer minor unit.
     *
     * @param array{
     *   clickId?: string,
     *   eventId?: string,
     *   eventName?: string,
     *   customerExternalId: string,
     *   customerName?: string,
     *   customerEmail?: string,
     *   customerAvatar?: string,
     *   amount: int,
     *   currency?: string,
     *   paymentProcessor?: string,
     *   invoiceId?: string,
     *   occurredAt?: string,
     *   metadata?: array<string, mixed>
     * } $input
     * @param array{idempotencyKey?: string, requestId?: string} $options
     * @return array<string, mixed>
     */
    public function sale(array $input, array $options = []): array
    {
        $invoiceId = $this->optionalId($input['invoiceId'] ?? null);
        $paymentProcessor = $this->optionalId($input['paymentProcessor'] ?? null) ?? 'custom';
        $input['eventId'] = $this->optionalId($input['eventId'] ?? null)
            ?? ($invoiceId !== null
                ? $this->invoiceEventId($paymentProcessor, $invoiceId)
                : $this->client->uuid('evt'));

        return $this->client->post('v1/track/sale', $input, $options);
    }

    private function optionalId(mixed $value): ?string
    {
        $normalized = is_string($value) ? trim($value) : '';

        return $normalized !== '' ? $normalized : null;
    }

    private function invoiceEventId(string $paymentProcessor, string $invoiceId): string
    {
        $digest = rtrim(strtr(base64_encode(hash('sha256', $paymentProcessor.':'.$invoiceId, true)), '+/', '-_'), '=');

        return 'invoice_'.$digest;
    }
}
