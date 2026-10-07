<?php

declare(strict_types=1);

namespace Pulsar\Database\Routing;

use Pulsar\Api\Api;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\ConnectionManagerInterface;

/**
 * Decides which connection a statement goes to.
 *
 * `ConnectionManagerInterface` answers "give me the connection called X". Read/write
 * routing asks a different question — "given this SQL, where does it go" — and that
 * question had no interface, so {@see RoutingConnection} was pinned to the final
 * {@see RoutingConnectionManager} and the routing policy could not be replaced without
 * replacing the connection decorator with it.
 *
 * The two methods are the whole seam, and the split between them is the point:
 *
 *  - {@see connectionForQuery()} takes the statement and classifies it. It is the only
 *    method that may consume a pending single-query override, so calling it is what
 *    spends a `useReplica()`.
 *  - {@see routedConnection()} answers about the connection rather than about a
 *    statement, for callers asking for the driver, the dialect or the transaction state.
 *    It must leave a pending override alone — otherwise a query builder asking for the
 *    dialect swallows the caller's `useReplica()` before the query it was meant for.
 *
 * An implementation that gets that distinction wrong routes correctly and still breaks
 * callers, so it is stated here rather than left to the one implementation to embody.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
interface QueryRouterInterface extends ConnectionManagerInterface
{
    /**
     * The connection this statement should run on.
     *
     * May consume a pending single-query override.
     */
    public function connectionForQuery(string $sql): ConnectionInterface;

    /**
     * The connection currently in force: an open transaction's, else the primary.
     *
     * Must not consume a pending single-query override.
     */
    public function routedConnection(): ConnectionInterface;
}
