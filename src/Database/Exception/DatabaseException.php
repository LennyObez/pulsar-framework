<?php

declare(strict_types=1);

namespace Pulsar\Database\Exception;

use NoDiscard;
use Pulsar\Api\Api;
use RuntimeException;
use Throwable;

use function sprintf;

/**
 * Base exception for all database-related errors.
 *
 * Provides static factory methods for specific database error scenarios.
 */
#[Api(since: '1.0.0')]
final class DatabaseException extends RuntimeException
{
    /**
     * Failed to establish a database connection.
     */
    #[NoDiscard]
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
    #[NoDiscard]
    public static function connectionNotConfigured(string $name): self
    {
        return new self(sprintf('Database connection "%s" is not configured', $name));
    }

    /**
     * Query execution failed.
     */
    #[NoDiscard]
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
    #[NoDiscard]
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
    #[NoDiscard]
    public static function emptyResult(): self
    {
        return new self('Query returned an empty result set');
    }

    /**
     * Column not found in row data.
     */
    #[NoDiscard]
    public static function columnNotFound(string $column): self
    {
        return new self(sprintf('Column "%s" not found in row', $column));
    }

    /**
     * Type cast failed for a column value.
     */
    #[NoDiscard]
    public static function typeCastFailed(string $column, string $expectedType): self
    {
        return new self(sprintf('Cannot cast column "%s" to %s', $column, $expectedType));
    }

    /**
     * Transaction is already finished (committed or rolled back).
     */
    #[NoDiscard]
    public static function transactionAlreadyFinished(): self
    {
        return new self('Transaction has already been committed or rolled back');
    }

    /**
     * Migration execution failed.
     */
    #[NoDiscard]
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
    #[NoDiscard]
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
    #[NoDiscard]
    public static function migrationNotFound(string $version): self
    {
        return new self(sprintf('Migration "%s" not found', $version));
    }

    /**
     * Duplicate migration version detected.
     */
    #[NoDiscard]
    public static function duplicateMigrationVersion(string $version): self
    {
        return new self(sprintf('Duplicate migration version: %s', $version));
    }

    /**
     * Migration file is invalid (does not return a MigrationInterface).
     */
    #[NoDiscard]
    public static function migrationFileInvalid(string $path): self
    {
        return new self(sprintf('Migration file "%s" must return a MigrationInterface instance', $path));
    }

    /**
     * Connection pool has no available connections.
     */
    #[NoDiscard]
    public static function poolExhausted(int $maxConnections): self
    {
        return new self(sprintf('Connection pool exhausted (max: %d)', $maxConnections));
    }

    /**
     * Timed out waiting for a connection from the pool.
     */
    #[NoDiscard]
    public static function poolTimeout(float $waitedMs): self
    {
        return new self(sprintf('Timed out waiting for pooled connection after %.1fms', $waitedMs));
    }

    /**
     * Failover to a standby endpoint failed.
     */
    #[NoDiscard]
    public static function failoverFailed(string $reason, ?Throwable $previous = null): self
    {
        return new self(
            sprintf('Database failover failed: %s', $reason),
            previous: $previous,
        );
    }

    /**
     * Read/write routing could not resolve a connection.
     */
    #[NoDiscard]
    public static function routingError(string $reason): self
    {
        return new self(sprintf('Connection routing error: %s', $reason));
    }

    /**
     * Query cache operation failed.
     */
    #[NoDiscard]
    public static function cacheError(string $reason, ?Throwable $previous = null): self
    {
        return new self(
            sprintf('Query cache error: %s', $reason),
            previous: $previous,
        );
    }

    /**
     * Seeder was not found.
     */
    #[NoDiscard]
    public static function seederNotFound(string $name): self
    {
        return new self(sprintf('Seeder "%s" not found', $name));
    }

    /**
     * Seeder execution failed.
     */
    #[NoDiscard]
    public static function seederFailed(string $name, ?Throwable $previous = null): self
    {
        $message = sprintf('Seeder "%s" failed', $name);

        if ($previous !== null) {
            $message .= ': ' . $previous->getMessage();
        }

        return new self($message, previous: $previous);
    }

    /**
     * Seeder file is invalid.
     */
    #[NoDiscard]
    public static function seederInvalid(string $reason): self
    {
        return new self(sprintf('Invalid seeder: %s', $reason));
    }

    /**
     * Migration diff generation failed.
     */
    #[NoDiscard]
    public static function diffFailed(string $reason, ?Throwable $previous = null): self
    {
        return new self(
            sprintf('Migration diff failed: %s', $reason),
            previous: $previous,
        );
    }

    /**
     * A unique constraint was violated during an insert or update.
     */
    #[NoDiscard]
    public static function uniqueConstraintViolation(string $constraint, ?Throwable $previous = null): self
    {
        return new self(
            sprintf('Unique constraint violation: %s', $constraint),
            previous: $previous,
        );
    }
}
