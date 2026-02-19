<?php

declare(strict_types=1);

namespace Pulsar\Queue\Monitor;

use Pulsar\Api\Api;
use Pulsar\Queue\QueueDriverInterface;
use Throwable;

/**
 * Checks queue health based on driver connectivity and pending-job thresholds.
 *
 * Returns {@see HealthStatus::Unhealthy} when the driver cannot respond,
 * {@see HealthStatus::Degraded} when the pending-job count exceeds the
 * configured threshold, and {@see HealthStatus::Healthy} otherwise.
 */
#[Api(since: '1.0.0')]
final readonly class QueueHealthCheck
{
    public function __construct(
        private QueueDriverInterface $driver,
        private int $pendingThreshold = 10000,
    ) {}

    /**
     * Check the health of the given queue.
     */
    public function check(string $queue): HealthStatus
    {
        try {
            $pending = $this->driver->size($queue);
        } catch (Throwable) {
            return HealthStatus::Unhealthy;
        }

        if ($pending >= $this->pendingThreshold) {
            return HealthStatus::Degraded;
        }

        return HealthStatus::Healthy;
    }
}
