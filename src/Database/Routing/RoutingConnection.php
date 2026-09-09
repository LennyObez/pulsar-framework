<?php

declare(strict_types=1);

namespace Pulsar\Database\Routing;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Dialect\DialectInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\DriverVariant;
use Pulsar\Database\Result;
use Pulsar\Database\Statement;
use Pulsar\Database\Transaction;

/**
 * The connection that asks {@see RoutingConnectionManager} where each statement goes.
 *
 * Read/write routing needs the statement in order to classify it, and the only method
 * that takes one is {@see RoutingConnectionManager::connectionForQuery()}. Nothing in
 * the framework called it: the container resolved `ConnectionInterface` once, from
 * `connection()`, and handed that single connection to every caller. A deployment with
 * `read_write.enabled` set and replicas configured therefore sent every SELECT to the
 * primary, and the replicas were reachable only by calling `useReplica()` by hand
 * before each one. This class is the missing consumer: one `ConnectionInterface` that
 * routes per statement, so the configuration means what it says.
 *
 * ## What goes where
 *
 * `query()`, `execute()` and `prepare()` carry SQL and are routed by it. Everything
 * else does not:
 *
 *  - `beginTransaction()` and `transaction()` are routed as `BEGIN`, which the router
 *    classifies as a write. That puts the transaction on the primary, pins the rest of
 *    it there, and — because a transaction may write before it commits — marks the
 *    stickiness that keeps later reads off a replica that has not caught up yet. A
 *    read-only transaction pays for that too; the alternative is deciding a
 *    transaction's role from a statement that has not been issued.
 *  - `driver()`, `variant()`, `dialect()`, `name()`, `inTransaction()` and
 *    `lastInsertId()` ask about the connection rather than run anything, and go to
 *    {@see RoutingConnectionManager::routedConnection()} — the open transaction's
 *    connection, else the primary. That method leaves a pending single-query override
 *    alone, so a query builder asking for the dialect cannot swallow a `useReplica()`.
 */
#[Internal(reason: 'Wired in the composition root; resolve ConnectionInterface')]
final readonly class RoutingConnection implements ConnectionInterface
{
    public function __construct(
        private QueryRouterInterface $manager,
    ) {}

    #[Override]
    public function query(string $sql, array $bindings = []): Result
    {
        return $this->manager->connectionForQuery($sql)->query($sql, $bindings);
    }

    #[Override]
    public function execute(string $sql, array $bindings = []): int
    {
        return $this->manager->connectionForQuery($sql)->execute($sql, $bindings);
    }

    #[Override]
    public function prepare(string $sql): Statement
    {
        return $this->manager->connectionForQuery($sql)->prepare($sql);
    }

    #[Override]
    public function beginTransaction(): Transaction
    {
        return $this->manager->connectionForQuery('BEGIN')->beginTransaction();
    }

    /**
     * The callback receives the routed connection itself, not this decorator.
     *
     * A transaction is already pinned to one connection, so re-routing each statement
     * inside it could only ever return the same one; handing the callback the pinned
     * connection says so in the type it receives. This mirrors
     * {@see \Pulsar\Database\Monitor\MonitoredConnection::transaction()}.
     */
    #[Override]
    public function transaction(callable $callback): mixed
    {
        return $this->manager->connectionForQuery('BEGIN')->transaction($callback);
    }

    #[Override]
    public function lastInsertId(): string
    {
        return $this->manager->routedConnection()->lastInsertId();
    }

    #[Override]
    public function driver(): Driver
    {
        return $this->manager->routedConnection()->driver();
    }

    #[Override]
    public function variant(): DriverVariant
    {
        return $this->manager->routedConnection()->variant();
    }

    #[Override]
    public function dialect(): DialectInterface
    {
        return $this->manager->routedConnection()->dialect();
    }

    #[Override]
    public function name(): string
    {
        return $this->manager->routedConnection()->name();
    }

    #[Override]
    public function inTransaction(): bool
    {
        return $this->manager->routedConnection()->inTransaction();
    }

    /**
     * Drops every connection the manager holds, primary and replicas alike.
     *
     * A caller holding this object holds the routed default, not one host, so
     * disconnecting one of them and leaving the others open would answer the next
     * statement from a connection the caller believes it closed.
     */
    #[Override]
    public function disconnect(): void
    {
        $this->manager->disconnect();
    }
}
