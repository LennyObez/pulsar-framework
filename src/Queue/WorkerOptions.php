<?php

declare(strict_types=1);

namespace Pulsar\Queue;

use Pulsar\Api\Api;
use Pulsar\Config\QueueConfig;

/**
 * Immutable options governing worker lifecycle and resource limits.
 */
#[Api]
readonly class WorkerOptions
{
    public function __construct(
        public int $maxJobs = 1000,
        public int $maxMemoryMb = 128,
        public int $timeLimitSeconds = 3600,
        public int $sleepMs = 1000,
    ) {}

    /**
     * Build worker options from queue configuration.
     */
    public static function fromConfig(QueueConfig $config): self
    {
        return new self(
            maxJobs: $config->workerMaxJobs,
            maxMemoryMb: $config->workerMaxMemoryMb,
            timeLimitSeconds: $config->workerTimeLimitSeconds,
            sleepMs: $config->workerSleepMs,
        );
    }
}
