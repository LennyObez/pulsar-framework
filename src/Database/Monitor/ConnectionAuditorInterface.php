<?php

declare(strict_types=1);

namespace Pulsar\Database\Monitor;

use Pulsar\Api\Api;
use Pulsar\Database\Driver;

/**
 * Audits connection lifecycle events for observability and compliance.
 * @api
 */
#[Api(since: '1.0.0')]
interface ConnectionAuditorInterface
{
    /**
     * Log a new connection being established.
     */
    public function logConnect(string $connectionName, Driver $driver): void;

    /**
     * Log a connection being closed.
     */
    public function logDisconnect(string $connectionName): void;

    /**
     * Log a connection error.
     */
    public function logError(string $connectionName, string $error): void;

    /**
     * Log a failover event from one endpoint to another.
     */
    public function logFailover(string $from, string $to, string $reason): void;
}
