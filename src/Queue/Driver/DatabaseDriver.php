<?php

declare(strict_types=1);

namespace Pulsar\Queue\Driver;

use function bin2hex;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionManagerInterface;
use Pulsar\Database\Row;
use Pulsar\Queue\JobRecord;
use Pulsar\Queue\JobRecordStatus;
use Pulsar\Queue\QueueDriverInterface;
use Random\Engine\Secure;
use Random\RandomException;
use Random\Randomizer;

use function time;

/**
 * Database-backed queue driver using the `queue_jobs` table.
 *
 * Uses SELECT ... FOR UPDATE SKIP LOCKED for safe concurrent pop()
 * operations across multiple workers.
 *
 * Expected table schema:
 *   queue_jobs (
 *     id          VARCHAR(32) PRIMARY KEY,
 *     queue       VARCHAR(255) NOT NULL,
 *     job_class   VARCHAR(512) NOT NULL,
 *     payload     TEXT NOT NULL,
 *     status      VARCHAR(32) NOT NULL DEFAULT 'pending',
 *     attempts    INT NOT NULL DEFAULT 0,
 *     available_at INT NOT NULL,
 *     created_at  INT NOT NULL,
 *     reserved_at INT DEFAULT NULL
 *   )
 */
#[Internal(reason: 'Implementation detail — use QueueDriverInterface contract')]
final readonly class DatabaseDriver implements QueueDriverInterface
{
    private Randomizer $randomizer;

    public function __construct(
        private ConnectionManagerInterface $connections,
        ?Randomizer $randomizer = null,
    ) {
        $this->randomizer = $randomizer ?? new Randomizer(new Secure());
    }

    /** @throws RandomException If random byte generation fails */
    #[Override]
    public function push(string $queue, string $jobClass, string $payload, int $delay = 0): string
    {
        $id = bin2hex($this->randomizer->getBytes(16));
        $now = time();

        $this->connections->connection()->execute(
            'INSERT INTO queue_jobs (id, queue, job_class, payload, status, attempts, available_at, created_at)
             VALUES (:id, :queue, :job_class, :payload, :status, :attempts, :available_at, :created_at)',
            [
                'id' => $id,
                'queue' => $queue,
                'job_class' => $jobClass,
                'payload' => $payload,
                'status' => JobRecordStatus::Pending->value,
                'attempts' => 0,
                'available_at' => $now + $delay,
                'created_at' => $now,
            ],
        );

        return $id;
    }

    #[Override]
    public function pop(string $queue): ?JobRecord
    {
        $now = time();

        $connection = $this->connections->connection();

        return $connection->transaction(function () use ($connection, $queue, $now): ?JobRecord {
            $result = $connection->query(
                'SELECT id, queue, job_class, payload, attempts, status, created_at, available_at
                 FROM queue_jobs
                 WHERE queue = :queue
                   AND status = :status
                   AND available_at <= :now
                 ORDER BY available_at ASC
                 LIMIT 1
                 FOR UPDATE SKIP LOCKED',
                [
                    'queue' => $queue,
                    'status' => JobRecordStatus::Pending->value,
                    'now' => $now,
                ],
            );

            $row = $result->first();

            if ($row === null) {
                return null;
            }

            $id = $row->getString('id');
            $newAttempts = $row->getInt('attempts') + 1;

            $connection->execute(
                'UPDATE queue_jobs
                 SET status = :status, attempts = :attempts, reserved_at = :reserved_at
                 WHERE id = :id',
                [
                    'status' => JobRecordStatus::Processing->value,
                    'attempts' => $newAttempts,
                    'reserved_at' => $now,
                    'id' => $id,
                ],
            );

            return new JobRecord(
                id: $id,
                queue: $row->getString('queue'),
                jobClass: $row->getString('job_class'),
                payload: $row->getString('payload'),
                attempts: $newAttempts,
                status: JobRecordStatus::Processing,
                createdAt: $row->getInt('created_at'),
                availableAt: $row->getInt('available_at'),
            );
        });
    }

    #[Override]
    public function acknowledge(string $jobId): void
    {
        $this->connections->connection()->execute(
            'DELETE FROM queue_jobs WHERE id = :id',
            ['id' => $jobId],
        );
    }

    #[Override]
    public function reject(string $jobId, string $reason): void
    {
        $this->connections->connection()->execute(
            'UPDATE queue_jobs SET status = :status, reserved_at = NULL WHERE id = :id',
            [
                'status' => JobRecordStatus::Failed->value,
                'id' => $jobId,
            ],
        );
    }

    #[Override]
    public function size(string $queue): int
    {
        $result = $this->connections->connection()->query(
            'SELECT COUNT(*) AS cnt FROM queue_jobs WHERE queue = :queue AND status = :status',
            [
                'queue' => $queue,
                'status' => JobRecordStatus::Pending->value,
            ],
        );

        $row = $result->first();

        return $row !== null ? $row->getInt('cnt') : 0;
    }

    #[Override]
    public function purge(string $queue): int
    {
        return $this->connections->connection()->execute(
            'DELETE FROM queue_jobs WHERE queue = :queue',
            ['queue' => $queue],
        );
    }

    /**
     * @return list<JobRecord>
     */
    #[Override]
    public function findByStatus(JobRecordStatus $status): array
    {
        $result = $this->connections->connection()->query(
            'SELECT id, queue, job_class, payload, attempts, status, created_at, available_at
             FROM queue_jobs
             WHERE status = :status
             ORDER BY created_at ASC',
            ['status' => $status->value],
        );

        return $result->map(static fn(Row $row): JobRecord => new JobRecord(
            id: $row->getString('id'),
            queue: $row->getString('queue'),
            jobClass: $row->getString('job_class'),
            payload: $row->getString('payload'),
            attempts: $row->getInt('attempts'),
            status: JobRecordStatus::from($row->getString('status')),
            createdAt: $row->getInt('created_at'),
            availableAt: $row->getInt('available_at'),
        ));
    }
}
