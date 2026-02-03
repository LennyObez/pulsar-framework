<?php

declare(strict_types=1);

namespace Pulsar\Resilience\HealthCheck;

use DateTimeImmutable;
use Pulsar\Resilience\Exception\ResilienceException;

/**
 * Orchestrates health check execution.
 */
final class HealthCheckRunner
{
    /** @var array<string, HealthCheckInterface> */
    private array $checks = [];

    /**
     * Register a health check.
     */
    public function register(HealthCheckInterface $check): void
    {
        $this->checks[$check->getName()] = $check;
    }

    /**
     * Run all registered health checks and produce a report.
     */
    public function runAll(): HealthReport
    {
        $results = [];
        $worstStatus = HealthStatus::Healthy;

        foreach ($this->checks as $check) {
            $result = $check->check();
            $results[] = $result;

            if ($result->status === HealthStatus::Unhealthy) {
                $worstStatus = HealthStatus::Unhealthy;
            } elseif ($result->status === HealthStatus::Degraded && $worstStatus !== HealthStatus::Unhealthy) {
                $worstStatus = HealthStatus::Degraded;
            }
        }

        return new HealthReport(
            overallStatus: $worstStatus,
            results: $results,
            generatedAt: new DateTimeImmutable(),
        );
    }

    /**
     * Run a single health check by name.
     *
     * @throws ResilienceException If the check is not registered.
     */
    public function run(string $name): HealthCheckResult
    {
        if (!isset($this->checks[$name])) {
            throw ResilienceException::healthCheckFailed($name, 'health check not registered');
        }

        return $this->checks[$name]->check();
    }

    /**
     * Get all registered check names.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->checks);
    }
}
