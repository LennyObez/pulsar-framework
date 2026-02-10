<?php

declare(strict_types=1);

namespace Pulsar\Database\Pool;

use Pulsar\Api\Api;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Exception\DatabaseException;

/**
 * Manages a pool of reusable database connections for persistent runtimes.
 *
 * Under FPM, no pool is created — connections are managed per-request
 * by the ConnectionManager directly.
 */
#[Api(since: '1.0.0')]
interface ConnectionPoolInterface
{
    /**
     * Check out a connection from the pool.
     *
     * @throws DatabaseException If the pool is exhausted or timed out.
     */
    public function checkout(): ConnectionInterface;

    /**
     * Return a connection to the pool.
     */
    public function checkin(ConnectionInterface $connection): void;

    /**
     * Drain the pool by disconnecting and removing all connections.
     */
    public function drain(): void;

    /**
     * Get current pool statistics.
     */
    public function stats(): PoolStats;
}
