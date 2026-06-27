<?php

declare(strict_types=1);

namespace Pulsar\Database\Migration;

use InvalidArgumentException;
use Pulsar\Api\Api;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Exception\DatabaseException;
use Pulsar\Database\Row;
use Pulsar\Database\Schema\SchemaException;
use Pulsar\Database\Schema\SchemaIdentifier;
use Throwable;

use function array_diff_key;
use function array_filter;
use function array_values;
use function fclose;
use function flock;
use function fopen;
use function is_int;
use function is_scalar;
use function sprintf;
use function sys_get_temp_dir;
use function usort;

use const DIRECTORY_SEPARATOR;
use const LOCK_EX;
use const LOCK_UN;

/**
 * Orchestrates running and rolling back migrations.
 *
 * Tracks migration state in a database table. Each `runPending()` call
 * assigns one batch number; `rollbackLastBatch()` rolls back all
 * migrations in the highest batch in reverse version order.
 *
 * All mutation methods acquire a database-level advisory lock (PostgreSQL
 * and MySQL) or a filesystem flock (SQLite) to prevent concurrent migration
 * runs from corrupting state.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class MigrationRunner implements MigrationRunnerInterface
{
    /**
     * Advisory lock key derived from CRC32 of the table name.
     * Deterministic across processes for the same migration table.
     */
    private int $advisoryLockKey;

    /**
     * @throws InvalidArgumentException If the migrations table name is not a valid
     *                                  SQL identifier. The name is interpolated into
     *                                  DDL/DML, MySQL GET_LOCK/RELEASE_LOCK string
     *                                  literals, and the SQLite flock path, so it must
     *                                  be validated before any of those are composed.
     */
    public function __construct(
        private ConnectionInterface $connection,
        private MigrationRepository $repository,
        private string $tableName,
    ) {
        try {
            SchemaIdentifier::validateTable($this->tableName);
        } catch (SchemaException $e) {
            throw new InvalidArgumentException(
                sprintf('MigrationRunner: invalid migrations table name "%s": %s', $this->tableName, $e->getMessage()),
                previous: $e,
            );
        }

        // Use a positive 31-bit integer for MySQL GET_LOCK / pg_advisory_lock compatibility
        $this->advisoryLockKey = crc32('pulsar_migrate:' . $this->tableName) & 0x7FFF_FFFF;
    }

    /**
     * F11.13: current schema version of the migration-tracking table
     * itself. Bumped when a future Pulsar release adds a new column
     * (e.g. `applied_by`, `duration_ms`). The {@see ensureMigrationTable()}
     * method reads the existing schema_version from the meta row and
     * compares against this constant, so deployments that pre-date a
     * column addition can be evolved in place rather than requiring a
     * manual `ALTER TABLE` per operator.
     */
    private const int META_TABLE_SCHEMA_VERSION = 1;

    /**
     * Ensure the migration tracking table exists.
     *
     * @throws DatabaseException
     */
    public function ensureMigrationTable(): void
    {
        $ddl = $this->createTableDdl();

        try {
            $this->connection->execute($ddl);
        } catch (Throwable $e) {
            throw DatabaseException::migrationTableError('could not create migration table', $e);
        }
    }

    /**
     * Run all pending migrations.
     *
     * Acquires a database-level advisory lock to prevent concurrent runs.
     *
     * @return list<string> List of applied version strings.
     * @throws DatabaseException
     */
    public function runPending(): array
    {
        $this->ensureMigrationTable();

        $this->acquireAdvisoryLock();

        try {
            $pending = $this->getPending();
            if ($pending === []) {
                return [];
            }

            $batch = $this->getCurrentBatch() + 1;
            $applied = [];

            foreach ($pending as $file) {
                $migration = $this->repository->load($file->path);

                try {
                    $this->executeMigrationSafely($migration, 'up');
                } catch (Throwable $e) {
                    throw DatabaseException::migrationFailed($file->version, 'up', $e);
                }

                $this->recordMigration($file, $batch);
                $applied[] = $file->version;
            }

            return $applied;
        } finally {
            $this->releaseAdvisoryLock();
        }
    }

    /**
     * Rollback the last batch of migrations.
     *
     * Acquires a database-level advisory lock to prevent concurrent runs.
     *
     * @return list<string> List of rolled-back version strings.
     * @throws DatabaseException
     */
    public function rollbackLastBatch(): array
    {
        $this->ensureMigrationTable();

        $this->acquireAdvisoryLock();

        try {
            $currentBatch = $this->getCurrentBatch();
            if ($currentBatch === 0) {
                return [];
            }

            return $this->rollbackBatch($currentBatch);
        } finally {
            $this->releaseAdvisoryLock();
        }
    }

    /**
     * Rollback all migrations down to (and including) a target version.
     *
     * Acquires a database-level advisory lock to prevent concurrent runs.
     *
     * @return list<string> List of rolled-back version strings.
     * @throws DatabaseException
     */
    public function rollbackTo(string $targetVersion): array
    {
        $this->ensureMigrationTable();

        $this->acquireAdvisoryLock();

        try {
            $applied = $this->getApplied();
            $toRollback = [];

            foreach ($applied as $record) {
                if ($record->version >= $targetVersion) {
                    $toRollback[] = $record;
                }
            }

            // Sort in reverse version order
            usort($toRollback, static fn(MigrationRecord $a, MigrationRecord $b): int => $b->version <=> $a->version);

            return $this->rollbackRecords($toRollback);
        } finally {
            $this->releaseAdvisoryLock();
        }
    }

    /**
     * Reset all migrations (rollback everything).
     *
     * Acquires a database-level advisory lock to prevent concurrent runs.
     *
     * F11.14: this method is **not atomic**. Each migration's `down()`
     * runs in its own statement (or its own transaction if it opens
     * one); a failure mid-loop leaves the database in a partial state
     * with the earlier migrations already rolled back. Wrapping the
     * whole loop in a single transaction is not viable on MySQL
     * (DDL statements force implicit commits, breaking the atomic
     * boundary), so the loop intentionally runs without an outer
     * transaction.
     *
     * Operators using `reset()` for disaster-recovery should expect
     * a partial-state outcome on failure, capture the returned list
     * of versions that did roll back, and re-run `reset()` after
     * fixing the offending migration. Postgres-only deployments
     * comfortable with the DDL-in-transaction guarantee can wrap
     * `reset()` in their own `connection->transaction(...)` call.
     *
     * @return list<string> List of rolled-back version strings.
     * @throws DatabaseException
     */
    public function reset(): array
    {
        $this->ensureMigrationTable();

        $this->acquireAdvisoryLock();

        try {
            $applied = $this->getApplied();
            if ($applied === []) {
                return [];
            }

            // Sort in reverse version order
            $sorted = $applied;
            usort($sorted, static fn(MigrationRecord $a, MigrationRecord $b): int => $b->version <=> $a->version);

            return $this->rollbackRecords($sorted);
        } finally {
            $this->releaseAdvisoryLock();
        }
    }

    /**
     * Get all applied migration records.
     *
     * @return list<MigrationRecord>
     */
    public function getApplied(): array
    {
        $result = $this->connection->query(
            sprintf('SELECT * FROM %s ORDER BY version ASC', $this->tableName),
        );

        return $result->map(static fn(Row $row): MigrationRecord => MigrationRecord::fromArray($row->toArray()));
    }

    /**
     * Get all pending migration files (not yet applied).
     *
     * @return list<MigrationFile>
     */
    public function getPending(): array
    {
        // Re-scan the filesystem: a long-running process (or a single
        // process issuing several operations) may have new migration
        // files dropped in since the last discovery. The repository
        // memoises discovery for intra-operation reuse, so we invalidate
        // at this operation boundary to reflect current on-disk state.
        $this->repository->clearCache();

        $allFiles = $this->repository->discover();
        $applied = $this->getApplied();

        $appliedVersions = [];
        foreach ($applied as $record) {
            $appliedVersions[$record->version] = true;
        }

        $pending = array_diff_key($allFiles, $appliedVersions);

        return array_values($pending);
    }

    /**
     * Get the current (highest) batch number.
     */
    public function getCurrentBatch(): int
    {
        $result = $this->connection->query(
            sprintf('SELECT MAX(batch) as max_batch FROM %s', $this->tableName),
        );

        $first = $result->first();
        if ($first === null) {
            return 0;
        }

        /** @var mixed $maxBatch */
        $maxBatch = $first->getOrDefault('max_batch', 0);

        return is_int($maxBatch) ? $maxBatch : 0;
    }

    /**
     * Rollback all migrations in a specific batch.
     *
     * @return list<string>
     */
    private function rollbackBatch(int $batch): array
    {
        $applied = $this->getApplied();
        $batchRecords = array_filter(
            $applied,
            static fn(MigrationRecord $r): bool => $r->batch === $batch,
        );

        // Sort in reverse version order
        $batchRecords = array_values($batchRecords);
        usort($batchRecords, static fn(MigrationRecord $a, MigrationRecord $b): int => $b->version <=> $a->version);

        return $this->rollbackRecords($batchRecords);
    }

    /**
     * Execute rollback for the given migration records.
     *
     * @param list<MigrationRecord> $records Records to roll back (must be pre-sorted)
     * @return list<string> List of rolled-back version strings
     * @throws DatabaseException
     */
    private function rollbackRecords(array $records): array
    {
        $rolledBack = [];

        // Single discovery point for every rollback operation
        // (rollbackLastBatch / rollbackTo / reset). Invalidate the
        // memoised scan so we match the version records against the
        // migration files present on disk right now, not a stale list.
        $this->repository->clearCache();

        $allFiles = $this->repository->discover();

        foreach ($records as $record) {
            if (!isset($allFiles[$record->version])) {
                throw DatabaseException::migrationNotFound($record->version);
            }

            $file = $allFiles[$record->version];
            $migration = $this->repository->load($file->path);

            try {
                $this->executeMigrationSafely($migration, 'down');
            } catch (Throwable $e) {
                throw DatabaseException::migrationFailed($record->version, 'down', $e);
            }

            $this->removeMigrationRecord($record->version);
            $rolledBack[] = $record->version;
        }

        return $rolledBack;
    }

    /**
     * Record a migration as applied.
     */
    private function recordMigration(MigrationFile $file, int $batch): void
    {
        $this->connection->execute(
            sprintf(
                'INSERT INTO %s (version, name, batch) VALUES (:version, :name, :batch)',
                $this->tableName,
            ),
            [
                'version' => $file->version,
                'name' => $file->name,
                'batch' => $batch,
            ],
        );
    }

    /**
     * Remove a migration record.
     */
    private function removeMigrationRecord(string $version): void
    {
        $this->connection->execute(
            sprintf('DELETE FROM %s WHERE version = :version', $this->tableName),
            ['version' => $version],
        );
    }

    /**
     * Execute a migration safely, avoiding nested transaction crashes on SQLite.
     *
     * If the connection already has an active transaction, the migration runs
     * without wrapping. Otherwise, the runner wraps it in a transaction for
     * atomicity. This prevents "cannot start a transaction within a transaction"
     * errors when CMS migrations manage their own transactions internally.
     */
    private function executeMigrationSafely(MigrationInterface $migration, string $direction): void
    {
        if ($this->connection->inTransaction()) {
            // Already in a transaction (e.g., migration manages its own).
            // Run directly without wrapping.
            $direction === 'up' ? $migration->up($this->connection) : $migration->down($this->connection);

            return;
        }

        $this->connection->transaction(function (ConnectionInterface $conn) use ($migration, $direction): void {
            $direction === 'up' ? $migration->up($conn) : $migration->down($conn);
        });
    }

    /**
     * Generate driver-aware DDL for the migration tracking table.
     *
     * Version column uses VARCHAR(30) to accommodate path-prefixed
     * sequential versions (e.g., "f827_00000000000042").
     */
    private function createTableDdl(): string
    {
        $driver = $this->connection->driver();
        $table = $this->tableName;

        // F11.13: every fresh-install schema carries a `schema_version`
        // column initialised to META_TABLE_SCHEMA_VERSION. Future
        // Pulsar releases that need to add columns to this table read
        // the current schema_version row, then issue ALTER TABLE
        // upgrades in `ensureMigrationTable()` before continuing.
        return match ($driver) {
            Driver::SQLite => sprintf(
                'CREATE TABLE IF NOT EXISTS %s ('
                . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
                . 'version VARCHAR(30) NOT NULL UNIQUE, '
                . 'name VARCHAR(255) NOT NULL, '
                . 'batch INTEGER NOT NULL, '
                . 'applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, '
                . 'schema_version INTEGER NOT NULL DEFAULT %d'
                . ')',
                $table,
                self::META_TABLE_SCHEMA_VERSION,
            ),
            Driver::MySQL => sprintf(
                'CREATE TABLE IF NOT EXISTS %s ('
                . 'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, '
                . 'version VARCHAR(30) NOT NULL UNIQUE, '
                . 'name VARCHAR(255) NOT NULL, '
                . 'batch INT UNSIGNED NOT NULL, '
                . 'applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, '
                . 'schema_version INT UNSIGNED NOT NULL DEFAULT %d'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
                $table,
                self::META_TABLE_SCHEMA_VERSION,
            ),
            Driver::PostgreSQL => sprintf(
                'CREATE TABLE IF NOT EXISTS %s ('
                . 'id SERIAL PRIMARY KEY, '
                . 'version VARCHAR(30) NOT NULL UNIQUE, '
                . 'name VARCHAR(255) NOT NULL, '
                . 'batch INTEGER NOT NULL, '
                . 'applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, '
                . 'schema_version INTEGER NOT NULL DEFAULT %d'
                . ')',
                $table,
                self::META_TABLE_SCHEMA_VERSION,
            ),
        };
    }

    // ------------------------------------------------------------------
    // Advisory locking
    // ------------------------------------------------------------------

    /**
     * Acquire a database-level advisory lock to prevent concurrent migration runs.
     *
     * PostgreSQL: pg_advisory_lock (session-level, blocks until acquired).
     * MySQL: GET_LOCK with a 30-second timeout.
     * SQLite: File-based flock (exclusive) since SQLite has no advisory lock primitive.
     *
     * @throws DatabaseException When the lock cannot be acquired
     */
    private function acquireAdvisoryLock(): void
    {
        $driver = $this->connection->driver();

        match ($driver) {
            Driver::PostgreSQL => $this->connection->execute(
                sprintf('SELECT pg_advisory_lock(%d)', $this->advisoryLockKey),
            ),
            Driver::MySQL => $this->acquireMysqlLock(),
            Driver::SQLite => $this->acquireSqliteFlock(),
        };
    }

    /**
     * Release the advisory lock acquired by {@see acquireAdvisoryLock()}.
     */
    private function releaseAdvisoryLock(): void
    {
        $driver = $this->connection->driver();

        match ($driver) {
            Driver::PostgreSQL => $this->connection->execute(
                sprintf('SELECT pg_advisory_unlock(%d)', $this->advisoryLockKey),
            ),
            Driver::MySQL => $this->connection->execute(
                sprintf("SELECT RELEASE_LOCK('pulsar_migrate_%s')", $this->tableName),
            ),
            Driver::SQLite => $this->releaseSqliteFlock(),
        };
    }

    /**
     * Acquire a MySQL named lock with a 30-second timeout.
     *
     * @throws DatabaseException When the lock cannot be acquired
     */
    private function acquireMysqlLock(): void
    {
        $result = $this->connection->query(
            sprintf("SELECT GET_LOCK('pulsar_migrate_%s', 30) AS acquired", $this->tableName),
        );

        $row = $result->first();
        /** @var mixed $acquired */
        $acquired = $row?->getOrDefault('acquired', 0);

        // GET_LOCK returns 1 (acquired), 0 (timeout), or NULL (error).
        // Coerce only the numeric/scalar shapes we expect; any other
        // shape from a misbehaving driver falls through to the throw.
        $value = is_scalar($acquired) ? (int) $acquired : 0;

        if ($value !== 1) {
            throw DatabaseException::migrationTableError(
                'Could not acquire migration lock; another migration may be running',
                null,
            );
        }
    }

    /**
     * Acquire a file-based exclusive lock for SQLite.
     *
     * Uses a function-scoped static variable to hold the file handle across
     * acquire/release calls, since the class is readonly and cannot hold
     * mutable instance or static properties.
     *
     * @throws DatabaseException When the lock file cannot be opened or locked
     */
    private function acquireSqliteFlock(): void
    {
        $lockPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_migrate_' . $this->tableName . '.lock';
        $handle = fopen($lockPath, 'cb');

        if ($handle === false) {
            throw DatabaseException::migrationTableError(
                'Could not open migration lock file: ' . $lockPath,
                null,
            );
        }

        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            throw DatabaseException::migrationTableError(
                'Could not acquire migration file lock',
                null,
            );
        }

        MigrationFlockHolder::set($lockPath, $handle);
    }

    /**
     * Release the SQLite file-based lock.
     */
    private function releaseSqliteFlock(): void
    {
        $lockPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_migrate_' . $this->tableName . '.lock';
        $handle = MigrationFlockHolder::get($lockPath);

        if ($handle !== null) {
            flock($handle, LOCK_UN);
            fclose($handle);
            MigrationFlockHolder::clear($lockPath);
        }
    }
}
