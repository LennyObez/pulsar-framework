<?php

declare(strict_types=1);

namespace Pulsar\ServiceDiscovery\Event;

use Pulsar\Api\Api;

/**
 * Dispatched when a service instance is registered with the discovery system.
 */
#[Api(since: '1.0.0')]
final readonly class ServiceRegistered
{
    public function __construct(
        public string $serviceName,
        public string $host,
        public int $port,
        public ?int $ttlSeconds,
        public int $occurredAt,
    ) {}
}
