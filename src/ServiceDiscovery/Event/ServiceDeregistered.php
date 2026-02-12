<?php

declare(strict_types=1);

namespace Pulsar\ServiceDiscovery\Event;

use Pulsar\Api\Api;

/**
 * Dispatched when a service instance is removed from the discovery system.
 */
#[Api(since: '1.0.0')]
final readonly class ServiceDeregistered
{
    public function __construct(
        public string $serviceName,
        public string $host,
        public int $port,
        public string $reason,
        public int $occurredAt,
    ) {}
}
