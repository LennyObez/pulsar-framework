<?php

declare(strict_types=1);

namespace Pulsar\Database\Health;

use Pulsar\Api\Api;
use Pulsar\Database\ConnectionInterface;

/**
 * Contract for monitoring health of multiple database connections.
 */
#[Api(since: '1.0.0')]
interface HeartbeatMonitorInterface
{
    /**
     * Register a connection for health monitoring.
     */
    public function register(string $name, ConnectionInterface $connection): void;

    /**
     * Unregister a connection from monitoring.
     */
    public function unregister(string $name): void;

    /**
     * Check all registered connections and return their health status.
     *
     * @param callable(string, bool): void|null $onStatusChange
     * @return array<string, bool>
     */
    public function checkAll(?callable $onStatusChange = null): array;

    /**
     * Check if a connection is registered.
     */
    public function isRegistered(string $name): bool;

    /**
     * Get the last known health status of all connections.
     *
     * @return array<string, bool>
     */
    public function status(): array;
}
