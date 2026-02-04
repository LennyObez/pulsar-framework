<?php

declare(strict_types=1);

namespace Pulsar\Resilience\HealthCheck;

use Pulsar\Database\ConnectionManagerInterface;

use function sprintf;

use Throwable;

/**
 * Health check that verifies database connectivity.
 *
 * Executes a `SELECT 1` query and measures response time.
 */
readonly class DatabaseHealthCheck implements HealthCheckInterface
{
    public function __construct(
        private ConnectionManagerInterface $connectionManager,
        private ?string $connectionName = null,
    ) {}

    public function getName(): string
    {
        return 'database';
    }

    public function check(): HealthCheckResult
    {
        $start = microtime(true);

        try {
            $connection = $this->connectionManager->connection($this->connectionName);
            $connection->query('SELECT 1');
            $elapsed = (microtime(true) - $start) * 1000.0;

            if ($elapsed > 1000) {
                return HealthCheckResult::degraded(
                    $this->getName(),
                    sprintf('Database responded in %.1fms (slow)', $elapsed),
                    $elapsed,
                );
            }

            return HealthCheckResult::healthy(
                $this->getName(),
                sprintf('Database responded in %.1fms', $elapsed),
                $elapsed,
            );
        } catch (Throwable $e) {
            $elapsed = (microtime(true) - $start) * 1000.0;

            return HealthCheckResult::unhealthy(
                $this->getName(),
                sprintf('Database check failed: %s', $e->getMessage()),
                $elapsed,
            );
        }
    }
}
