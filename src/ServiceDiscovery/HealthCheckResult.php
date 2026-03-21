<?php

declare(strict_types=1);

namespace Pulsar\ServiceDiscovery;

use Pulsar\Api\Api;

/**
 * Result of a service health check.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class HealthCheckResult
{
    public function __construct(
        public ServiceHealthStatus $status,
        public ?string $message = null,
        public ?float $latencyMs = null,
        public ?int $checkedAt = null,
    ) {}

    public static function healthy(?float $latencyMs = null): self
    {
        return new self(
            status: ServiceHealthStatus::Healthy,
            latencyMs: $latencyMs,
            checkedAt: time(),
        );
    }

    public static function unhealthy(string $reason): self
    {
        return new self(
            status: ServiceHealthStatus::Unhealthy,
            message: $reason,
            checkedAt: time(),
        );
    }

    public static function degraded(string $reason, ?float $latencyMs = null): self
    {
        return new self(
            status: ServiceHealthStatus::Degraded,
            message: $reason,
            latencyMs: $latencyMs,
            checkedAt: time(),
        );
    }
}
