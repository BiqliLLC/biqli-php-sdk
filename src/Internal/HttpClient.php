<?php

declare(strict_types=1);

namespace Biqli\Sdk\Internal;

use Biqli\Sdk\Exceptions\BiqliException;
use Biqli\Sdk\Version;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/** @internal */
final class HttpClient
{
    private const DEFAULT_BASE_URL = 'https://biq.li/api';

    private readonly string $apiKey;

    private readonly string $baseUrl;

    private readonly int $timeoutMs;

    private readonly int $maxRetries;

    private readonly int $baseDelayMs;

    private readonly int $maxDelayMs;

    private readonly bool $jitter;

    private readonly string $clientName;

    private readonly string $clientVersion;

    private readonly ClientInterface $http;

    /**
     * @param array{
     *   baseUrl?: string,
     *   timeoutMs?: int,
     *   clientName?: string,
     *   clientVersion?: string,
     *   retry?: array{maxRetries?: int, baseDelayMs?: int, maxDelayMs?: int, jitter?: bool},
     *   httpClient?: ClientInterface
     * } $options
     */
    public function __construct(string $apiKey, array $options = [])
    {
        $this->apiKey = $this->validateApiKey($apiKey);
        $this->baseUrl = $this->normalizeBaseUrl($options['baseUrl'] ?? null);
        $this->timeoutMs = max(1000, $this->boundedInteger($options['timeoutMs'] ?? null, 10000, 120000));

        $retry = is_array($options['retry'] ?? null) ? $options['retry'] : [];
        $this->maxRetries = $this->boundedInteger($retry['maxRetries'] ?? null, 2, 8);
        $this->baseDelayMs = $this->boundedInteger($retry['baseDelayMs'] ?? null, 250, 60000);
        $this->maxDelayMs = max(
            $this->baseDelayMs,
            $this->boundedInteger($retry['maxDelayMs'] ?? null, 5000, 60000),
        );
        $this->jitter = ($retry['jitter'] ?? true) !== false;
        $this->clientName = $this->validateHeader(
            'clientName',
            trim((string) ($options['clientName'] ?? Version::SDK_NAME)),
            64,
        );
        $this->clientVersion = $this->validateHeader(
            'clientVersion',
            trim((string) ($options['clientVersion'] ?? Version::SDK_VERSION)),
            64,
        );
        $this->http = $options['httpClient'] ?? new Client();
    }

    /**
     * @param array<string, mixed> $payload
     * @param array{idempotencyKey?: string, requestId?: string} $options
     * @return array<string, mixed>
     */
    public function post(string $path, array $payload, array $options = []): array
    {
        $body = $this->encode($payload);
        $eventId = is_string($payload['eventId'] ?? null) ? trim($payload['eventId']) : '';
        $idempotencyKey = $this->validateHeader(
            'idempotencyKey',
            trim((string) ($options['idempotencyKey'] ?? $eventId)),
            255,
        );
        $requestId = $this->requestId($options['requestId'] ?? null);
        $lastError = null;

        for ($attempt = 1; $attempt <= $this->maxRetries + 1; $attempt++) {
            $response = null;

            try {
                $response = $this->http->request('POST', $this->baseUrl.'/'.$path, [
                    'allow_redirects' => false,
                    'connect_timeout' => $this->timeoutMs / 1000,
                    'http_errors' => false,
                    'timeout' => $this->timeoutMs / 1000,
                    'headers' => [
                        'Accept' => 'application/json',
                        'Authorization' => 'Bearer '.$this->apiKey,
                        'Content-Type' => 'application/json',
                        'Idempotency-Key' => $idempotencyKey,
                        'X-Biq-Client' => $this->clientName,
                        'X-Biq-Client-Version' => $this->clientVersion,
                        'X-Biq-Request-Id' => $requestId,
                    ],
                    'body' => $body,
                ]);

                $decoded = $this->decode($response, $attempt);
                $status = $response->getStatusCode();
                if ($status >= 200 && $status < 300) {
                    return $decoded;
                }

                $lastError = BiqliException::fromResponse(
                    $status,
                    $decoded,
                    $this->responseHeader($response, 'X-Biq-Request-Id'),
                    $attempt,
                );
            } catch (BiqliException $exception) {
                $lastError = $exception;
            } catch (GuzzleException $exception) {
                $lastError = new BiqliException(
                    message: 'The Biqli API could not be reached.',
                    errorCode: 'network_error',
                    retryable: true,
                    attempts: $attempt,
                    previous: $exception,
                );
            } catch (Throwable $exception) {
                throw new BiqliException(
                    message: 'The Biqli request failed before it could be sent.',
                    errorCode: 'request_failed',
                    attempts: $attempt,
                    previous: $exception,
                );
            }

            if (!$lastError->retryable || $attempt > $this->maxRetries) {
                throw $lastError;
            }

            $retryAfter = $response instanceof ResponseInterface
                ? $this->retryAfterMs($response)
                : null;
            $this->sleep($retryAfter ?? $this->retryDelay($attempt));
        }

        throw $lastError ?? new BiqliException(
            message: 'The Biqli request failed.',
            errorCode: 'request_failed',
        );
    }

    private function validateApiKey(string $value): string
    {
        $key = trim($value);
        if (preg_match('/^biqli_(?!pk_)[A-Za-z0-9_-]+$/D', $key) !== 1) {
            throw new BiqliException(
                message: 'Provide a valid secret Biqli workspace API key.',
                errorCode: 'invalid_api_key',
            );
        }

        return $key;
    }

    private function normalizeBaseUrl(?string $value): string
    {
        $url = trim($value ?: self::DEFAULT_BASE_URL);
        $parts = parse_url($url);
        if (
            $parts === false
            || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || isset($parts['user'], $parts['pass'], $parts['query'], $parts['fragment'])
        ) {
            throw new BiqliException(
                message: 'The Biqli base URL must be an HTTP or HTTPS URL without credentials, query, or fragment.',
                errorCode: 'invalid_base_url',
            );
        }

        return rtrim($url, '/');
    }

    private function boundedInteger(mixed $value, int $fallback, int $maximum): int
    {
        return is_int($value) && $value >= 0 ? min($value, $maximum) : $fallback;
    }

    private function validateHeader(string $name, string $value, int $maximum): string
    {
        if ($value === '' || strlen($value) > $maximum || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new BiqliException(
                message: "{$name} is invalid.",
                errorCode: 'invalid_request_option',
            );
        }

        return $value;
    }

    private function requestId(mixed $candidate): string
    {
        $value = $this->validateHeader(
            'requestId',
            is_string($candidate) && trim($candidate) !== '' ? trim($candidate) : $this->uuid('req'),
            100,
        );
        if (preg_match('/^[A-Za-z0-9._:-]+$/D', $value) !== 1) {
            throw new BiqliException(
                message: 'requestId is invalid.',
                errorCode: 'invalid_request_option',
            );
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encode(array $payload): string
    {
        try {
            return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new BiqliException(
                message: 'The Biqli request payload is not valid JSON data.',
                errorCode: 'invalid_payload',
                previous: $exception,
            );
        }
    }

    /** @return array<string, mixed> */
    private function decode(ResponseInterface $response, int $attempt): array
    {
        $contents = (string) $response->getBody();
        if ($contents === '') {
            return [];
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new BiqliException(
                message: 'The Biqli API returned an invalid JSON response.',
                errorCode: 'invalid_response',
                status: $response->getStatusCode(),
                requestId: $this->responseHeader($response, 'X-Biq-Request-Id'),
                retryable: BiqliException::isRetryableStatus($response->getStatusCode()),
                attempts: $attempt,
                previous: $exception,
            );
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new BiqliException(
                message: 'The Biqli API returned an invalid JSON response.',
                errorCode: 'invalid_response',
                status: $response->getStatusCode(),
                requestId: $this->responseHeader($response, 'X-Biq-Request-Id'),
                retryable: BiqliException::isRetryableStatus($response->getStatusCode()),
                attempts: $attempt,
            );
        }

        return $decoded;
    }

    private function responseHeader(ResponseInterface $response, string $name): ?string
    {
        $value = trim($response->getHeaderLine($name));

        return $value !== '' ? $value : null;
    }

    private function retryAfterMs(ResponseInterface $response): ?int
    {
        $value = trim($response->getHeaderLine('Retry-After'));
        if ($value === '') {
            return null;
        }
        if (ctype_digit($value)) {
            return min(((int) $value) * 1000, 60000);
        }

        $timestamp = strtotime($value);
        return $timestamp === false ? null : max(0, min(($timestamp - time()) * 1000, 60000));
    }

    private function retryDelay(int $attempt): int
    {
        $delay = min($this->maxDelayMs, $this->baseDelayMs * (2 ** max(0, $attempt - 1)));
        if (!$this->jitter) {
            return $delay;
        }

        return max(0, (int) round($delay * (random_int(80, 120) / 100)));
    }

    private function sleep(int $milliseconds): void
    {
        if ($milliseconds > 0) {
            usleep($milliseconds * 1000);
        }
    }

    public function uuid(string $prefix): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        $uuid = substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'
            .substr($hex, 16, 4).'-'.substr($hex, 20);

        return $prefix.'_'.$uuid;
    }
}
