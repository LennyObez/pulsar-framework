<?php

declare(strict_types=1);

namespace Pulsar\Runtime;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Result of a connection warmup attempt.
 */
#[Api(since: '1.0.0')]
final readonly class ConnectionWarmupResult
{
    /**
     * @param list<string> $errors Error messages from failed warmups
     */
    public function __construct(
        public int $total,
        public int $succeeded,
        public int $failed,
        public array $errors,
        public float $elapsedMs,
    ) {}

    #[NoDiscard]
    public function allSucceeded(): bool
    {
        return $this->failed === 0;
    }
}
