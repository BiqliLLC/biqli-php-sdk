<?php

declare(strict_types=1);

namespace Biqli\Sdk\Exceptions;

use RuntimeException;
use Throwable;

final class BiqliException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'unknown_error',
        public readonly int $status = 0,
        public readonly ?string $requestId = null,
        public readonly mixed $details = null,
        public readonly bool $retryable = false,
        public readonly int $attempts = 1,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromResponse(
        int $status,
        array $body,
        ?string $requestId,
        int $attempts,
    ): self {
        $error = is_array($body['error'] ?? null) ? $body['error'] : [];

        return new self(
            message: self::stringValue($error['message'] ?? $body['message'] ?? null)
                ?? "Biqli request failed with status {$status}.",
            errorCode: self::stringValue($error['code'] ?? null) ?? 'request_failed',
            status: $status,
            requestId: self::stringValue($body['request_id'] ?? null) ?? $requestId,
            details: $error['details'] ?? null,
            retryable: self::isRetryableStatus($status),
            attempts: $attempts,
        );
    }

    public static function isRetryableStatus(int $status): bool
    {
        return in_array($status, [408, 425, 429], true) || $status >= 500;
    }

    private static function stringValue(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
