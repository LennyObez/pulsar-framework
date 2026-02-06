<?php

declare(strict_types=1);

namespace Pulsar\Queue\Retry;

use function min;

use NoDiscard;

use function pow;

use Pulsar\Api\Api;
use Pulsar\Config\QueueConfig;
use Throwable;

/**
 * Exponential backoff retry policy for failed queue jobs.
 *
 * Calculates increasing delays between retry attempts using the formula:
 *   delay = min(baseDelayMs * multiplier^(attempt - 1), maxDelayMs)
 */
#[Api]
final readonly class QueueRetryPolicy
{
    public function __construct(
        private int $maxAttempts,
        private int $baseDelayMs,
        private int $maxDelayMs,
        private float $multiplier,
    ) {}

    /**
     * Determine whether a failed job should be retried, dead-lettered, or discarded.
     */
    public function shouldRetry(int $attempt, ?Throwable $exception = null): RetryDecision
    {
        if ($attempt >= $this->maxAttempts) {
            return RetryDecision::DeadLetter;
        }

        return RetryDecision::Retry;
    }

    /**
     * Calculate the delay in milliseconds before the next retry attempt.
     *
     * Uses exponential backoff: baseDelayMs * multiplier^(attempt - 1), capped at maxDelayMs.
     */
    public function getDelay(int $attempt): int
    {
        $delay = (int) ((float) $this->baseDelayMs * pow($this->multiplier, $attempt - 1));

        return min($delay, $this->maxDelayMs);
    }

    /**
     * Build a retry policy from queue configuration.
     */
    #[NoDiscard]
    public static function fromConfig(QueueConfig $config): self
    {
        return new self(
            maxAttempts: $config->retryMaxAttempts,
            baseDelayMs: $config->retryBaseDelayMs,
            maxDelayMs: $config->retryMaxDelayMs,
            multiplier: $config->retryMultiplier,
        );
    }
}
