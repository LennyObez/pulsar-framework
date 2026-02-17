<?php

declare(strict_types=1);

namespace Pulsar\Extension\Observability\Export\Otlp\Transport;

use Pulsar\Api\Internal;

/**
 * Immutable result of an OTLP transport send operation.
 */
#[Internal]
final readonly class TransportResult
{
    public function __construct(
        public bool $success,
        public int $httpStatus,
        public string $errorMessage,
        public bool $retryable,
    ) {}

    public static function success(int $httpStatus): self
    {
        return new self(
            success: true,
            httpStatus: $httpStatus,
            errorMessage: '',
            retryable: false,
        );
    }

    public static function failure(int $httpStatus, string $message, bool $retryable = false): self
    {
        return new self(
            success: false,
            httpStatus: $httpStatus,
            errorMessage: $message,
            retryable: $retryable,
        );
    }
}
