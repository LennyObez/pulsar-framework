<?php

declare(strict_types=1);

namespace Pulsar\Database;

/**
 * Database connection interface.
 *
 * Provides a thin abstraction over PDO for executing queries,
 * preparing statements, and managing transactions.
 */
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
     * Get the database driver.
     */
    public function driver(): Driver;

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
