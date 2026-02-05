<?php

declare(strict_types=1);

namespace Pulsar\Database;

use Pulsar\Api\Api;

/**
 * Interface for managing multiple database connections.
 */
#[Api]
interface ConnectionManagerInterface
{
    /**
     * Get a connection by name, or the default connection if no name is given.
     */
    public function connection(?string $name = null): ConnectionInterface;

    /**
     * Get the default connection name.
     */
    public function getDefaultConnectionName(): string;

    /**
     * Disconnect a specific connection (or all connections if name is null).
     */
    public function disconnect(?string $name = null): void;
}
