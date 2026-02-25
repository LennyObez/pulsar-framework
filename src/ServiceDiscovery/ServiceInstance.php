<?php

declare(strict_types=1);

namespace Pulsar\ServiceDiscovery;

use Pulsar\Api\Api;

use function sprintf;

/**
 * Immutable value object representing a discovered service instance.
 *
 * Each instance captures the network location (host, port, scheme),
 * health status, and arbitrary metadata for a single service endpoint.
 *
 * @param array<string, string> $metadata
 */
#[Api(since: '1.0.0')]
final readonly class ServiceInstance
{
    /**
     * @param array<string, string> $metadata
     */
    public function __construct(
        public string $name,
        public string $host,
        public int $port,
        public string $scheme = 'https',
        public bool $healthy = true,
        public array $metadata = [],
    ) {}

    /**
     * Returns the base URI for this service instance.
     */
    public function uri(): string
    {
        return sprintf('%s://%s:%d', $this->scheme, $this->host, $this->port);
    }
}
