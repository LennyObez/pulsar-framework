<?php

declare(strict_types=1);

namespace Pulsar\Database\Health;

use Override;
use Pulsar\Api\Api;
use Pulsar\Database\ConnectionInterface;
use Throwable;

/**
 * Checks connection liveness by executing a lightweight query.
 *
 * Uses `SELECT 1` to verify that the underlying connection is alive
 * and responding within the configured timeout.
 */
#[Api(since: '1.0.0')]
final readonly class ConnectionHealthChecker implements ConnectionHealthCheckerInterface
{
    public function __construct(
        private float $timeoutSeconds = 5.0,
    ) {}

    #[Override]
    public function isHealthy(ConnectionInterface $connection): bool
    {
        $start = microtime(true);

        try {
            $connection->query('SELECT 1');
            $elapsed = microtime(true) - $start;

            return $elapsed <= $this->timeoutSeconds;
        } catch (Throwable) {
            return false;
        }
    }
}
