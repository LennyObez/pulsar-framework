<?php

declare(strict_types=1);

namespace Pulsar\Database\Migration;

use function array_diff_key;
use function array_filter;
use function array_values;

use Pulsar\Api\Api;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Exception\DatabaseException;
use Pulsar\Database\Row;

use function sprintf;

use Throwable;

use function usort;

/**
 * Orchestrates running and rolling back migrations.
 *
 * Tracks migration state in a database table. Each `runPending()` call
 * assigns one batch number; `rollbackLastBatch()` rolls back all
 * migrations in the highest batch in reverse version order.
 */
#[Api]
final readonly class MigrationRunner
{
    public function __construct(
        private ConnectionInterface $connection,
        private MigrationRepository $repository,
        private string $tableName,
    ) {}

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
     * @return list<string> List of applied version strings.
     * @throws DatabaseException
     */
    public function runPending(): array
    {
        $this->ensureMigrationTable();

        $pending = $this->getPending();
        if ($pending === []) {
            return [];
        }

        $batch = $this->getCurrentBatch() + 1;
        $applied = [];

        foreach ($pending as $file) {
            $migration = $this->repository->load($file->path);

            try {
                $this->connection->transaction(function (ConnectionInterface $conn) use ($migration): void {
                    $migration->up($conn);
                });
            } catch (Throwable $e) {
                throw DatabaseException::migrationFailed($file->version, 'up', $e);
            }

            $this->recordMigration($file, $batch);
            $applied[] = $file->version;
        }

        return $applied;
    }

    /**
     * Rollback the last batch of migrations.
     *
     * @return list<string> List of rolled-back version strings.
     * @throws DatabaseException
     */
    public function rollbackLastBatch(): array
    {
        $this->ensureMigrationTable();

        $currentBatch = $this->getCurrentBatch();
        if ($currentBatch === 0) {
            return [];
        }

        return $this->rollbackBatch($currentBatch);
    }

    /**
     * Rollback all migrations down to (and including) a target version.
     *
     * @return list<string> List of rolled-back version strings.
     * @throws DatabaseException
     */
    public function rollbackTo(string $targetVersion): array
    {
        $this->ensureMigrationTable();

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
    }

    /**
     * Reset all migrations (rollback everything).
     *
     * @return list<string> List of rolled-back version strings.
     * @throws DatabaseException
     */
    public function reset(): array
    {
        $this->ensureMigrationTable();

        $applied = $this->getApplied();
        if ($applied === []) {
            return [];
        }

        // Sort in reverse version order
        $sorted = $applied;
        usort($sorted, static fn(MigrationRecord $a, MigrationRecord $b): int => $b->version <=> $a->version);

        return $this->rollbackRecords($sorted);
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

        $maxBatch = $first->getOrDefault('max_batch', 0);

        /** @var int|null $value */
        $value = $maxBatch;

        return $value ?? 0;
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
        $allFiles = $this->repository->discover();

        foreach ($records as $record) {
            if (!isset($allFiles[$record->version])) {
                throw DatabaseException::migrationNotFound($record->version);
            }

            $file = $allFiles[$record->version];
            $migration = $this->repository->load($file->path);

            try {
                $this->connection->transaction(function (ConnectionInterface $conn) use ($migration): void {
                    $migration->down($conn);
                });
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
     * Generate driver-aware DDL for the migration tracking table.
     */
    private function createTableDdl(): string
    {
        $driver = $this->connection->driver();
        $table = $this->tableName;

        return match ($driver) {
            Driver::SQLite => sprintf(
                'CREATE TABLE IF NOT EXISTS %s ('
                . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
                . 'version VARCHAR(14) NOT NULL UNIQUE, '
                . 'name VARCHAR(255) NOT NULL, '
                . 'batch INTEGER NOT NULL, '
                . 'applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP'
                . ')',
                $table,
            ),
            Driver::MySQL => sprintf(
                'CREATE TABLE IF NOT EXISTS %s ('
                . 'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, '
                . 'version VARCHAR(14) NOT NULL UNIQUE, '
                . 'name VARCHAR(255) NOT NULL, '
                . 'batch INT UNSIGNED NOT NULL, '
                . 'applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
                $table,
            ),
            Driver::PostgreSQL => sprintf(
                'CREATE TABLE IF NOT EXISTS %s ('
                . 'id SERIAL PRIMARY KEY, '
                . 'version VARCHAR(14) NOT NULL UNIQUE, '
                . 'name VARCHAR(255) NOT NULL, '
                . 'batch INTEGER NOT NULL, '
                . 'applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP'
                . ')',
                $table,
            ),
        };
    }
}
