<?php

declare(strict_types=1);

namespace Pulsar\Runtime;

use Pulsar\Api\Api;
use RuntimeException;

/**
 * Contract for connections that can be pre-established during worker boot.
 *
 * Implementors should open the physical connection when warmConnection()
 * is called (e.g. execute a lightweight query, send a PING, etc.).
 */
#[Api(since: '1.0.0')]
interface ConnectionWarmable
{
    /**
     * Establish the connection. Must be idempotent.
     *
     * @throws RuntimeException If the connection cannot be established
     */
    public function warmConnection(): void;

    /**
     * Human-readable name for logging (e.g. "database:default", "redis:sessions").
     */
    public function connectionName(): string;
}
