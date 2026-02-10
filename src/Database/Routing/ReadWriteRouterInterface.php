<?php

declare(strict_types=1);

namespace Pulsar\Database\Routing;

use Pulsar\Api\Api;

/**
 * Routes SQL statements to read or write connections.
 *
 * Classifies queries by their first keyword and supports pinning
 * all traffic to the primary after a write operation.
 */
#[Api(since: '1.0.0')]
interface ReadWriteRouterInterface
{
    /**
     * Determine the connection role for a SQL statement.
     */
    public function route(string $sql): ConnectionRole;

    /**
     * Pin all subsequent queries to the primary connection.
     *
     * @param int|null $durationMs Duration in milliseconds, or null for request-scoped
     */
    public function pinToPrimary(?int $durationMs = null): void;

    /**
     * Check if currently pinned to the primary connection.
     */
    public function isPinnedToPrimary(): bool;

    /**
     * Reset primary pin and return to normal routing.
     */
    public function reset(): void;
}
