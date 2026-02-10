<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Internal\Exception;

use Pulsar\Api\Internal;
use RuntimeException;

/**
 * Exception thrown by the OpenTelemetry extension for export and configuration errors.
 */
#[Internal(reason: 'Internal exception for OpenTelemetry export failures')]
final class OpenTelemetryException extends RuntimeException
{
    public static function exportFailed(string $signal, string $reason): self
    {
        return new self("Failed to export $signal: $reason");
    }

    public static function queueOverflow(string $signal, int $maxSize): self
    {
        return new self("$signal queue overflow: max queue size $maxSize reached, dropping oldest items");
    }

    public static function invalidConfiguration(string $message): self
    {
        return new self("Invalid OpenTelemetry configuration: $message");
    }
}
