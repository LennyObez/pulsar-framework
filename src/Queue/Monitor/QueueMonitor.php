<?php

declare(strict_types=1);

namespace Pulsar\Queue\Monitor;

use Pulsar\Api\Api;

/**
 * Default implementation of the queue monitor contract.
 *
 * Delegates metrics to {@see MetricsCollector} and health checks to
 * {@see QueueHealthCheck}.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class QueueMonitor implements QueueMonitorInterface
{
    public function __construct(
        private MetricsCollector $metricsCollector,
        private QueueHealthCheck $healthCheck,
    ) {}

    public function metrics(): MetricsCollector
    {
        return $this->metricsCollector;
    }

    public function healthCheck(string $queue): QueueHealthStatus
    {
        return $this->healthCheck->check($queue);
    }
}
