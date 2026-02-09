<?php

declare(strict_types=1);

namespace Pulsar\Resilience\HealthCheck;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Aggregated report from running all health checks.
 */
#[Api(since: '1.0.0')]
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
