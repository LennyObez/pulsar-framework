<?php

declare(strict_types=1);

namespace Pulsar\Database\Health;

use Override;
use Pulsar\Api\Api;
use Pulsar\Database\ConnectionInterface;

/**
 * Periodically checks health of all registered connections.
 *
 * Designed for persistent runtimes where interval-based polling is meaningful.
 * Reports status changes via a configurable callback and removes dead connections
 * from tracking after a configurable number of consecutive failures.
 * @api
 */
#[Api(since: '1.0.0')]
final class HeartbeatMonitor implements HeartbeatMonitorInterface
{
    /** @var array<string, ConnectionInterface> */
    private array $connections = [];

    /** @var array<string, int> consecutive failure counts */
    private array $failureCounts = [];

    /** @var array<string, bool> last known health status */
    private array $healthStatus = [];

    public function __construct(
        private readonly ConnectionHealthCheckerInterface $healthChecker,
        /** @var int max consecutive failures before removing a connection */
        private readonly int $maxConsecutiveFailures = 3,
    ) {}

    #[Override]
    public function register(string $name, ConnectionInterface $connection): void
    {
        $this->connections[$name] = $connection;
        $this->failureCounts[$name] = 0;
        $this->healthStatus[$name] = true;
    }

    #[Override]
    public function unregister(string $name): void
    {
        unset(
            $this->connections[$name],
            $this->failureCounts[$name],
            $this->healthStatus[$name],
        );
    }

    /**
     * @param callable(string, bool): void|null $onStatusChange
     * @return array<string, bool>
     */
    #[Override]
    public function checkAll(?callable $onStatusChange = null): array
    {
        $results = [];
        $toRemove = [];

        foreach ($this->connections as $name => $connection) {
            $healthy = $this->healthChecker->isHealthy($connection);
            $results[$name] = $healthy;

            if ($healthy) {
                $this->failureCounts[$name] = 0;

                if (!$this->healthStatus[$name]) {
                    $this->healthStatus[$name] = true;

                    if ($onStatusChange !== null) {
                        $onStatusChange($name, true);
                    }
                }
            } else {
                $this->failureCounts[$name]++;

                if ($this->healthStatus[$name]) {
                    $this->healthStatus[$name] = false;

                    if ($onStatusChange !== null) {
                        $onStatusChange($name, false);
                    }
                }

                if ($this->failureCounts[$name] >= $this->maxConsecutiveFailures) {
                    $toRemove[] = $name;
                }
            }
        }

        foreach ($toRemove as $name) {
            $this->unregister($name);
        }

        return $results;
    }

    #[Override]
    public function isRegistered(string $name): bool
    {
        return isset($this->connections[$name]);
    }

    /**
     * @return array<string, bool>
     */
    #[Override]
    public function status(): array
    {
        return $this->healthStatus;
    }
}
