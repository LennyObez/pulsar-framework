<?php

declare(strict_types=1);

namespace Pulsar\ServiceDiscovery\Event;

use Pulsar\Api\Api;
use Pulsar\ServiceDiscovery\ServiceHealthStatus;

/**
 * Dispatched when a service instance's health status changes.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ServiceHealthChanged
{
    public function __construct(
        public string $serviceName,
        public string $host,
        public int $port,
        public ServiceHealthStatus $previousStatus,
        public ServiceHealthStatus $newStatus,
        public int $occurredAt,
    ) {}
}
