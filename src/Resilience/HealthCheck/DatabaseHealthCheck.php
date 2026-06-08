<?php

declare(strict_types=1);

namespace Pulsar\Resilience\HealthCheck;

use Override;
use Pulsar\Database\ConnectionManagerInterface;
use Throwable;

use function sprintf;

/**
 * Health check that verifies database connectivity.
 *
 * Executes a `SELECT 1` query and measures response time.
 */
final readonly class DatabaseHealthCheck implements HealthCheckInterface
{
    public function __construct(
        private ConnectionManagerInterface $connectionManager,
        private ?string $connectionName = null,
    ) {}

    #[Override]
    public function getName(): string
    {
        return 'database';
    }

    #[Override]
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
