<?php

declare(strict_types=1);

namespace Pulsar\Resilience;

use Throwable;

/**
 * Result of a retry policy execution.
 */
readonly class RetryResult
{
    /**
     * @param list<int> $attemptDelays Delay in milliseconds before each attempt
     */
    public function __construct(
        public bool $succeeded,
        public int $attempts,
        public mixed $result,
        public ?Throwable $lastException,
        public array $attemptDelays,
    ) {}

    /**
     * Create a successful result.
     *
     * @param list<int> $attemptDelays
     */
    public static function success(mixed $result, int $attempts, array $attemptDelays): self
    {
        return new self(
            succeeded: true,
            attempts: $attempts,
            result: $result,
            lastException: null,
            attemptDelays: $attemptDelays,
        );
    }

    /**
     * Create a failed result after exhausting all attempts.
     *
     * @param list<int> $attemptDelays
     */
    public static function exhausted(int $attempts, Throwable $lastException, array $attemptDelays): self
    {
        return new self(
            succeeded: false,
            attempts: $attempts,
            result: null,
            lastException: $lastException,
            attemptDelays: $attemptDelays,
        );
    }
}
