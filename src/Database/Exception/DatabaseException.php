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
 * @api
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
     * Read/write routing was configured on a driver whose DSN carries no host.
     *
     * SQLite addresses a file, so a "replica" derived from the primary would open the
     * same file under a different name. Refused rather than accepted, because routing
     * that reports replica traffic which is really the primary is a metric an operator
     * would size their infrastructure on.
     */
    #[NoDiscard]
    public static function readWriteRoutingUnsupportedDriver(string $driver, string $host): self
    {
        return new self(sprintf(
            'Read/write routing lists "%s", but the "%s" driver addresses no host, so no '
            . 'connection distinct from the primary can be derived from it. Remove the '
            . 'read_write.read_hosts / read_write.write_host entries, or name a connection '
            . 'configured under "connections" instead of a host.',
            $host,
            $driver,
        ));
    }

    /**
     * Read/write routing is configured with replicas, and something has taken the
     * routing manager's place.
     *
     * Routing needs the statement in order to classify it, and only
     * {@see \Pulsar\Database\Routing\RoutingConnectionManager::connectionForQuery()}
     * takes one. Multi-tenancy and failover both decorate
     * `ConnectionManagerInterface` after the database is wired, and their decorators
     * offer no such method — so behind one of them every read would go to the primary
     * while the configuration said replicas were in use. Refused at boot rather than
     * reported as routing that is not happening.
     */
    #[NoDiscard]
    public static function readWriteRoutingDecoratedAway(string $manager): self
    {
        return new self(sprintf(
            'Read/write routing is enabled with read hosts configured, but the connection '
            . 'manager in the container is %s rather than the routing manager. Routing '
            . 'classifies each statement, and that decorator cannot pass a statement '
            . 'through, so every read would go to the primary. Turn off read_write.enabled, '
            . 'or turn off the feature that replaced the manager (multi-tenancy, failover).',
            $manager,
        ));
    }

    /**
     * The query cache was asked for, and there is no PSR-16 cache to put results in.
     *
     * Answered with a refusal rather than a runner that silently never caches: an
     * application resolving the runner has decided caching is safe for its workload,
     * and a cache that quietly does nothing is the shape of "configured and inert"
     * this whole subsystem was fixed to stop being.
     */
    #[NoDiscard]
    public static function queryCacheNeedsACacheStore(): self
    {
        return new self(
            'The query cache needs a PSR-16 cache to store results in, and none is registered. '
            . 'Add config/cache.php so the cache subsystem is wired, or stop resolving '
            . 'CachedQueryRunner.',
        );
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
     * The work could not be undone, because the engine had already committed it.
     *
     * MySQL commits implicitly at every DDL statement. A rollback issued after one of
     * those has nothing left to reverse, and reporting success would tell the caller its
     * changes were withdrawn when they are permanent.
     *
     * The reason the caller was abandoning its work is attached by
     * {@see \Pulsar\Database\PdoConnection::transaction()}, which holds it; this factory
     * describes only what the engine did.
     */
    #[NoDiscard]
    public static function rollbackImpossibleAfterImplicitCommit(): self
    {
        return new self(
            'Rollback is impossible: the engine ended the transaction on its own — MySQL '
            . 'does this at every DDL statement — so any changes it committed are permanent.',
        );
    }

    /**
     * The rollback itself failed.
     *
     * The original failure is attached rather than discarded: a rollback fault replacing
     * it leaves the operator holding a message about savepoints and no idea what went
     * wrong in the first place.
     *
     * @param Throwable $cause The failure the rollback was abandoning
     */
    #[NoDiscard]
    public static function rollbackFailed(string $reason, Throwable $cause): self
    {
        return new self(
            sprintf('Rollback failed (%s). The failure it was abandoning is attached.', $reason),
            previous: $cause,
        );
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
     * The migrations table records versions this checkout no longer produces.
     *
     * Raised INSTEAD of running, never alongside it: the migrations the table records
     * are on disk unapplied under a different version string, so a run would apply
     * them a second time to a database that already carries them.
     */
    #[NoDiscard]
    public static function migrationIdentityMismatch(string $details): self
    {
        return new self('Migration identity mismatch. ' . $details);
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

    /**
     * A query builder helper was invoked with an empty value list.
     *
     * Used by InListBuilder when callers pass `count = 0` or an empty
     * `$values` array — this is always a programming error rather than
     * a database failure.
     */
    #[NoDiscard]
    public static function emptyValueList(string $builderMethod): self
    {
        return new self(sprintf('%s requires at least one value', $builderMethod));
    }
}
