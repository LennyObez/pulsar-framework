<?php

declare(strict_types=1);

namespace Pulsar\Resilience\Exception;

use NoDiscard;
use Pulsar\Api\Api;
use RuntimeException;

use function sprintf;

use Throwable;

/**
 * Exception for resilience/self-healing errors.
 */
#[Api]
final class ResilienceException extends RuntimeException
{
    /**
     * Circuit breaker is open and rejecting calls.
     */
    #[NoDiscard]
    public static function circuitOpen(string $name): self
    {
        return new self(sprintf('Circuit breaker "%s" is open — calls are being rejected', $name));
    }

    /**
     * All retry attempts have been exhausted.
     */
    #[NoDiscard]
    public static function retryExhausted(string $operation, int $attempts, ?Throwable $lastException = null): self
    {
        return new self(
            sprintf('Retry exhausted for "%s" after %d attempt(s)', $operation, $attempts),
            previous: $lastException,
        );
    }

    /**
     * Health check failed.
     */
    #[NoDiscard]
    public static function healthCheckFailed(string $checkName, string $reason): self
    {
        return new self(sprintf('Health check "%s" failed: %s', $checkName, $reason));
    }

    /**
     * Repair action failed.
     */
    #[NoDiscard]
    public static function repairFailed(string $repairName, string $reason): self
    {
        return new self(sprintf('Repair "%s" failed: %s', $repairName, $reason));
    }
}
