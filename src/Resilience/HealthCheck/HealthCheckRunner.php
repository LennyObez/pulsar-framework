<?php

declare(strict_types=1);

namespace Pulsar\Resilience\HealthCheck;

use DateTimeImmutable;
use Pulsar\Resilience\Exception\ResilienceException;
use Throwable;

use function array_keys;
use function sprintf;

/**
 * Orchestrates health check execution.
 */
final class HealthCheckRunner implements HealthCheckRunnerInterface
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
            try {
                $result = $check->check();
            } catch (Throwable $e) {
                // A check is contracted not to throw, but a misbehaving implementation
                // must not abort the whole report. Record it as unhealthy and continue.
                // The exception class (not its message) is surfaced to avoid leaking
                // sensitive details into the report.
                $result = HealthCheckResult::unhealthy(
                    $check->getName(),
                    sprintf('Health check raised %s', $e::class),
                );
            }

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
