<?php

declare(strict_types=1);

namespace Pulsar\Resilience\HealthCheck;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;

/**
 * Result of a single health check.
 */
#[Api]
readonly class HealthCheckResult
{
    public function __construct(
        public string $name,
        public HealthStatus $status,
        public string $message,
        public float $responseTimeMs,
        public DateTimeImmutable $checkedAt,
    ) {}

    /**
     * Create a healthy result.
     */
    #[NoDiscard]
    public static function healthy(string $name, string $message = 'OK', float $responseTimeMs = 0.0): self
    {
        return new self(
            name: $name,
            status: HealthStatus::Healthy,
            message: $message,
            responseTimeMs: $responseTimeMs,
            checkedAt: new DateTimeImmutable(),
        );
    }

    /**
     * Create a degraded result.
     */
    #[NoDiscard]
    public static function degraded(string $name, string $message, float $responseTimeMs = 0.0): self
    {
        return new self(
            name: $name,
            status: HealthStatus::Degraded,
            message: $message,
            responseTimeMs: $responseTimeMs,
            checkedAt: new DateTimeImmutable(),
        );
    }

    /**
     * Create an unhealthy result.
     */
    #[NoDiscard]
    public static function unhealthy(string $name, string $message, float $responseTimeMs = 0.0): self
    {
        return new self(
            name: $name,
            status: HealthStatus::Unhealthy,
            message: $message,
            responseTimeMs: $responseTimeMs,
            checkedAt: new DateTimeImmutable(),
        );
    }
}
