<?php

declare(strict_types=1);

namespace Pulsar\ServiceDiscovery;

use Pulsar\Api\Internal;

/**
 * Internal wrapper that tracks TTL and health for a registered service instance.
 */
#[Internal]
final class ServiceTtlEntry
{
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public ServiceHealthStatus $healthStatus;

    public function __construct(
        public readonly ServiceInstance $instance,
        public readonly ?int $ttlSeconds,
        public int $registeredAt,
        public int $lastHeartbeat,
    ) {
        $this->healthStatus = $instance->healthy
            ? ServiceHealthStatus::Healthy
            : ServiceHealthStatus::Unhealthy;
    }

    public function isExpired(int $now): bool
    {
        if ($this->ttlSeconds === null) {
            return false;
        }

        return ($now - $this->lastHeartbeat) > $this->ttlSeconds;
    }

    public function refreshHeartbeat(int $now): void
    {
        $this->lastHeartbeat = $now;
    }

    public function key(): string
    {
        return $this->instance->host . ':' . $this->instance->port;
    }
}
