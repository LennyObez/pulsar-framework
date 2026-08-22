<?php

declare(strict_types=1);

namespace Pulsar\Queue\Monitor;

use Pulsar\Api\Api;

/**
 * Contract for queue monitoring capabilities.
 *
 * Provides access to collected metrics and health-check status
 * for individual queues.
 * @api
 */
#[Api(since: '1.0.0')]
interface QueueMonitorInterface
{
    /**
     * Get the metrics collector instance.
     */
    public function metrics(): MetricsCollector;

    /**
     * Check the health status of a specific queue.
     */
    public function healthCheck(string $queue): QueueHealthStatus;
}
