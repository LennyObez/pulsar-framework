<?php

declare(strict_types=1);

namespace Pulsar\Resilience\HealthCheck;

use DateTimeImmutable;

/**
 * Aggregated report from running all health checks.
 */
readonly class HealthReport
{
    /**
     * @param list<HealthCheckResult> $results
     */
    public function __construct(
        public HealthStatus $overallStatus,
        public array $results,
        public DateTimeImmutable $generatedAt,
    ) {}

    /**
     * Check if all health checks passed.
     */
    public function isHealthy(): bool
    {
        return $this->overallStatus === HealthStatus::Healthy;
    }
}
