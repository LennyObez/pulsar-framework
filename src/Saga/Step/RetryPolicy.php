<?php

declare(strict_types=1);

namespace Pulsar\Saga\Step;

use Pulsar\Api\Api;

/**
 * Retry configuration for saga step actions.
 *
 * Defines the maximum number of attempts and backoff strategy
 * for either forward or compensation direction of a saga step.
 */
#[Api(since: '1.0.0')]
final readonly class RetryPolicy
{
    /**
     * @param int<1, max>   $maxAttempts  Maximum number of attempts (including the initial try)
     * @param int<0, max>   $initialDelayMs  Initial delay in milliseconds before first retry
     */
    public function __construct(
        public int $maxAttempts = 1,
        public BackoffStrategy $backoff = BackoffStrategy::Exponential,
        public int $initialDelayMs = 100,
    ) {}

    /**
     * Calculate the delay in milliseconds for a given attempt number.
     *
     * @param int<1, max> $attempt The current attempt number (1-based)
     */
    public function delayForAttempt(int $attempt): int
    {
        if ($attempt <= 1) {
            return 0;
        }

        $retryNumber = $attempt - 1;

        return match ($this->backoff) {
            BackoffStrategy::Linear => $this->initialDelayMs * $retryNumber,
            BackoffStrategy::Exponential => (int) round((float) $this->initialDelayMs * (float) (2 ** ($retryNumber - 1))),
        };
    }
}
