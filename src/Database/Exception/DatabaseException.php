<?php

declare(strict_types=1);

namespace Pulsar\Database\Exception;

use RuntimeException;

use function sprintf;

use Throwable;

/**
 * Base exception for all database-related errors.
 *
 * Provides static factory methods for specific database error scenarios.
 */
final class DatabaseException extends RuntimeException
{
    /**
     * Failed to establish a database connection.
     */
    public static function connectionFailed(string $name, ?Throwable $previous = null): self
    {
        return new self(
            sprintf('Failed to connect to database "%s"', $name),
            previous: $previous,
        );
    }

    /**
     * Requested connection is not configured.
     */
    public static function connectionNotConfigured(string $name): self
    {
        return new self(sprintf('Database connection "%s" is not configured', $name));
    }

    /**
     * Query execution failed.
     */
    public static function queryFailed(string $sql, ?Throwable $previous = null): self
    {
        return new self(
            sprintf('Query failed: %s', $sql),
            previous: $previous,
        );
    }

    /**
     * Prepared statement creation failed.
     */
    public static function prepareError(string $sql, ?Throwable $previous = null): self
    {
        return new self(
            sprintf('Failed to prepare statement: %s', $sql),
            previous: $previous,
        );
    }

    /**
     * Expected a non-empty result set.
     */
    public static function emptyResult(): self
    {
        return new self('Query returned an empty result set');
    }

    /**
     * Column not found in row data.
     */
    public static function columnNotFound(string $column): self
    {
        return new self(sprintf('Column "%s" not found in row', $column));
    }

    /**
     * Type cast failed for a column value.
     */
    public static function typeCastFailed(string $column, string $expectedType): self
    {
        return new self(sprintf('Cannot cast column "%s" to %s', $column, $expectedType));
    }

    /**
     * Transaction is already finished (committed or rolled back).
     */
    public static function transactionAlreadyFinished(): self
    {
        return new self('Transaction has already been committed or rolled back');
    }

    /**
     * Migration execution failed.
     */
    public static function migrationFailed(string $version, string $direction, ?Throwable $previous = null): self
    {
        return new self(
            sprintf('Migration %s (%s) failed', $version, $direction),
            previous: $previous,
        );
    }

    /**
     * Migration tracking table could not be created or queried.
     */
    public static function migrationTableError(string $reason, ?Throwable $previous = null): self
    {
        return new self(
            sprintf('Migration table error: %s', $reason),
            previous: $previous,
        );
    }

    /**
     * Migration file not found on disk.
     */
    public static function migrationNotFound(string $version): self
    {
        return new self(sprintf('Migration "%s" not found', $version));
    }

    /**
     * Duplicate migration version detected.
     */
    public static function duplicateMigrationVersion(string $version): self
    {
        return new self(sprintf('Duplicate migration version: %s', $version));
    }

    /**
     * Migration file is invalid (does not return a MigrationInterface).
     */
    public static function migrationFileInvalid(string $path): self
    {
        return new self(sprintf('Migration file "%s" must return a MigrationInterface instance', $path));
    }
}
