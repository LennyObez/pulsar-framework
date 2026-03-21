<?php

declare(strict_types=1);

namespace Pulsar\Resilience\Exception;

use NoDiscard;
use Pulsar\Api\Api;
use RuntimeException;
use Throwable;

use function sprintf;

/**
 * Exception for resilience/self-healing errors.
 * @api
 */
#[Api(since: '1.0.0')]
final class ResilienceException extends RuntimeException
{
    /**
     * Circuit breaker is open and rejecting calls.
     */
    #[NoDiscard]
    public static function circuitOpen(string $name): self
    {
        return new self(sprintf('Circuit breaker "%s" is open: calls are being rejected', $name));
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

    /**
     * Operation exceeded the configured timeout.
     */
    #[NoDiscard]
    public static function timeout(string $operation, int $timeoutMs, int $elapsedMs): self
    {
        return new self(sprintf(
            'Operation "%s" timed out after %dms (limit: %dms)',
            $operation,
            $elapsedMs,
            $timeoutMs,
        ));
    }

    /**
     * Bulkhead rejected the request due to concurrency limit.
     */
    #[NoDiscard]
    public static function bulkheadFull(string $resource, int $maxConcurrent): self
    {
        return new self(sprintf(
            'Bulkhead for "%s" is full: %d concurrent executions already running',
            $resource,
            $maxConcurrent,
        ));
    }
}
