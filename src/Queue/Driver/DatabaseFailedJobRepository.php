<?php

declare(strict_types=1);

namespace Pulsar\Queue\Driver;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Row;
use Pulsar\Queue\FailedJob;
use Pulsar\Queue\FailedJobRepositoryInterface;

use function sprintf;

/**
 * Durable database-backed {@see FailedJobRepositoryInterface}.
 *
 * Persists dead-lettered jobs in the `failed_jobs` table so they survive worker
 * restarts — the audit/retention guarantee regulated domains require. The table
 * is distinct from the queue transport's own storage; it holds Pulsar-level
 * failure metadata (exception, attempts, failed-at) for inspect / retry /
 * regulated-delete / purge.
 *
 * Schema is created via {@see installSchema()}, intended to run from a
 * migration or deploy step (kept here so the DDL lives next to the queries).
 */
#[Internal]
final readonly class DatabaseFailedJobRepository implements FailedJobRepositoryInterface
{
    private const string TABLE = 'failed_jobs';

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    /**
     * Idempotent DDL: create the `failed_jobs` table. Safe to re-run.
     */
    public function installSchema(): void
    {
        match ($this->connection->driver()) {
            Driver::SQLite => $this->installSqliteSchema(),
            Driver::MySQL => $this->installMysqlSchema(),
            Driver::PostgreSQL => $this->installPostgresSchema(),
        };
    }

    #[Override]
    public function store(FailedJob $job): void
    {
        $bindings = [
            'id' => $job->id,
            'queue' => $job->queue,
            'job_class' => $job->jobClass,
            'payload' => $job->payload,
            'exception' => $job->exception,
            'failed_at' => $job->failedAt,
            'attempts' => $job->attempts,
        ];

        // Upsert keyed by id so a redelivered poison job overwrites rather than
        // duplicates. Each dialect spells the conflict resolution differently.
        $sql = match ($this->connection->driver()) {
            Driver::SQLite => sprintf(
                'INSERT OR REPLACE INTO %s (id, queue, job_class, payload, exception, failed_at, attempts) '
                . 'VALUES (:id, :queue, :job_class, :payload, :exception, :failed_at, :attempts)',
                self::TABLE,
            ),
            Driver::MySQL => sprintf(
                'INSERT INTO %s (id, queue, job_class, payload, exception, failed_at, attempts) '
                . 'VALUES (:id, :queue, :job_class, :payload, :exception, :failed_at, :attempts) '
                . 'ON DUPLICATE KEY UPDATE queue = VALUES(queue), job_class = VALUES(job_class), '
                . 'payload = VALUES(payload), exception = VALUES(exception), '
                . 'failed_at = VALUES(failed_at), attempts = VALUES(attempts)',
                self::TABLE,
            ),
            Driver::PostgreSQL => sprintf(
                'INSERT INTO %s (id, queue, job_class, payload, exception, failed_at, attempts) '
                . 'VALUES (:id, :queue, :job_class, :payload, :exception, :failed_at, :attempts) '
                . 'ON CONFLICT (id) DO UPDATE SET queue = EXCLUDED.queue, job_class = EXCLUDED.job_class, '
                . 'payload = EXCLUDED.payload, exception = EXCLUDED.exception, '
                . 'failed_at = EXCLUDED.failed_at, attempts = EXCLUDED.attempts',
                self::TABLE,
            ),
        };

        $this->connection->execute($sql, $bindings);
    }

    #[Override]
    public function find(string $id): ?FailedJob
    {
        $row = $this->connection->query(
            sprintf('SELECT id, queue, job_class, payload, exception, failed_at, attempts FROM %s WHERE id = :id', self::TABLE),
            ['id' => $id],
        )->first();

        return $row !== null ? $this->hydrate($row) : null;
    }

    #[Override]
    public function all(): array
    {
        return $this->connection->query(
            sprintf(
                'SELECT id, queue, job_class, payload, exception, failed_at, attempts FROM %s ORDER BY failed_at ASC',
                self::TABLE,
            ),
        )->map(fn(Row $row): FailedJob => $this->hydrate($row));
    }

    #[Override]
    public function forget(string $id): bool
    {
        return $this->connection->execute(
            sprintf('DELETE FROM %s WHERE id = :id', self::TABLE),
            ['id' => $id],
        ) > 0;
    }

    #[Override]
    public function flush(): int
    {
        $count = $this->count();
        $this->connection->execute(sprintf('DELETE FROM %s', self::TABLE));

        return $count;
    }

    #[Override]
    public function count(): int
    {
        $row = $this->connection->query(sprintf('SELECT COUNT(*) AS c FROM %s', self::TABLE))->first();

        return $row?->getInt('c') ?? 0;
    }

    private function hydrate(Row $row): FailedJob
    {
        return new FailedJob(
            id: $row->getString('id'),
            queue: $row->getString('queue'),
            jobClass: $row->getString('job_class'),
            payload: $row->getString('payload'),
            exception: $row->getString('exception'),
            failedAt: $row->getInt('failed_at'),
            attempts: $row->getInt('attempts'),
        );
    }

    private function installSqliteSchema(): void
    {
        $this->connection->execute(sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id TEXT PRIMARY KEY,
                queue TEXT NOT NULL,
                job_class TEXT NOT NULL,
                payload TEXT NOT NULL,
                exception TEXT NOT NULL,
                failed_at INTEGER NOT NULL,
                attempts INTEGER NOT NULL
            )',
            self::TABLE,
        ));
        $this->connection->execute(sprintf(
            'CREATE INDEX IF NOT EXISTS %s_failed_at_idx ON %s (failed_at)',
            self::TABLE,
            self::TABLE,
        ));
    }

    private function installMysqlSchema(): void
    {
        $this->connection->execute(sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id VARCHAR(255) NOT NULL PRIMARY KEY,
                queue VARCHAR(255) NOT NULL,
                job_class VARCHAR(255) NOT NULL,
                payload LONGTEXT NOT NULL,
                exception LONGTEXT NOT NULL,
                failed_at BIGINT NOT NULL,
                attempts INT NOT NULL,
                INDEX %s_failed_at_idx (failed_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin',
            self::TABLE,
            self::TABLE,
        ));
    }

    private function installPostgresSchema(): void
    {
        $this->connection->execute(sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id TEXT PRIMARY KEY,
                queue TEXT NOT NULL,
                job_class TEXT NOT NULL,
                payload TEXT NOT NULL,
                exception TEXT NOT NULL,
                failed_at BIGINT NOT NULL,
                attempts INTEGER NOT NULL
            )',
            self::TABLE,
        ));
        $this->connection->execute(sprintf(
            'CREATE INDEX IF NOT EXISTS %s_failed_at_idx ON %s (failed_at)',
            self::TABLE,
            self::TABLE,
        ));
    }
}
