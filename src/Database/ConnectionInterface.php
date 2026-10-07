<?php

declare(strict_types=1);

namespace Pulsar\Database;

use Pulsar\Api\Api;
use Pulsar\Database\Dialect\DialectInterface;

/**
 * Database connection interface.
 *
 * Provides a thin abstraction over PDO for executing queries,
 * preparing statements, and managing transactions.
 * @api
 */
#[Api(since: '1.0.0')]
interface ConnectionInterface
{
    /**
     * Execute a SELECT query and return the result.
     *
     * @param array<string, mixed> $bindings
     */
    public function query(string $sql, array $bindings = []): Result;

    /**
     * Execute an INSERT/UPDATE/DELETE statement and return the affected row count.
     *
     * @param array<string, mixed> $bindings
     */
    public function execute(string $sql, array $bindings = []): int;

    /**
     * Prepare a reusable statement.
     */
    public function prepare(string $sql): Statement;

    /**
     * Begin a new transaction (supports nesting via savepoints).
     */
    public function beginTransaction(): Transaction;

    /**
     * Execute a callback within a transaction.
     *
     * Automatically commits on success or rolls back on exception.
     *
     * @template T
     * @param callable(ConnectionInterface): T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed;

    /**
     * Get the last inserted row ID.
     */
    public function lastInsertId(): string;

    /**
     * Which PDO driver opened this connection.
     *
     * An identity, not a dispatch axis. Code that branches on the answer takes on the job
     * of knowing every engine the framework will ever support, which is what makes adding
     * one a breaking change. Ask {@see dialect()} for behaviour instead.
     */
    public function driver(): Driver;

    /**
     * Which member of the driver's engine family actually answered.
     *
     * MariaDB and Percona both connect through the MySQL driver; only the server's own
     * `VERSION()` string tells them apart. Resolving a dialect from {@see driver()} alone
     * therefore hands a real MariaDB server the MySQL dialect, silently costing it
     * `RETURNING` and `CREATE INDEX IF NOT EXISTS`.
     *
     * Engines with no variants answer {@see DriverVariant::Standard} without asking the
     * server anything.
     */
    public function variant(): DriverVariant;

    /**
     * How this connection's engine wants its SQL written.
     *
     * The intended way to ask an engine-dependent question: quoting, limits, upserts,
     * row-lock support. Resolved from the driver AND the variant, so what comes back is
     * the dialect of the server that actually answered.
     */
    public function dialect(): DialectInterface;

    /**
     * Get the connection name.
     */
    public function name(): string;

    /**
     * Check if currently inside a transaction.
     */
    public function inTransaction(): bool;

    /**
     * Disconnect from the database and release PDO resources.
     */
    public function disconnect(): void;
}
