<?php

declare(strict_types=1);

namespace Pulsar\Queue\Driver;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Queue\JobRecord;
use Pulsar\Queue\JobRecordStatus;
use Pulsar\Queue\QueueDriverInterface;
use Random\Engine\Secure;
use Random\RandomException;
use Random\Randomizer;

use function array_filter;
use function array_values;
use function bin2hex;
use function count;
use function time;

/**
 * In-memory array-backed queue driver for testing and development.
 *
 * Stores all job records in a PHP array. Records are lost when the
 * process exits. Provides a getAll() method for test inspection.
 */
#[Internal(reason: 'Testing driver — not suitable for production use')]
final class InMemoryDriver implements QueueDriverInterface
{
    /** @var array<string, JobRecord> */
    private array $records = [];

    private readonly Randomizer $randomizer;

    public function __construct(?Randomizer $randomizer = null)
    {
        $this->randomizer = $randomizer ?? new Randomizer(new Secure());
    }

    /** @throws RandomException If random byte generation fails */
    #[Override]
    public function push(string $queue, string $jobClass, string $payload, int $delay = 0): string
    {
        $id = bin2hex($this->randomizer->getBytes(16));
        $now = time();

        $this->records[$id] = new JobRecord(
            id: $id,
            queue: $queue,
            jobClass: $jobClass,
            payload: $payload,
            attempts: 0,
            status: JobRecordStatus::Pending,
            createdAt: $now,
            availableAt: $now + $delay,
        );

        return $id;
    }

    #[Override]
    public function pop(string $queue): ?JobRecord
    {
        $now = time();

        foreach ($this->records as $id => $record) {
            if (
                $record->queue === $queue
                && $record->status === JobRecordStatus::Pending
                && $record->availableAt <= $now
            ) {
                $processing = new JobRecord(
                    id: $record->id,
                    queue: $record->queue,
                    jobClass: $record->jobClass,
                    payload: $record->payload,
                    attempts: $record->attempts + 1,
                    status: JobRecordStatus::Processing,
                    createdAt: $record->createdAt,
                    availableAt: $record->availableAt,
                );

                $this->records[$id] = $processing;

                return $processing;
            }
        }

        return null;
    }

    #[Override]
    public function acknowledge(string $jobId): void
    {
        if (!isset($this->records[$jobId])) {
            return;
        }

        unset($this->records[$jobId]);
    }

    #[Override]
    public function reject(string $jobId, string $reason): void
    {
        if (!isset($this->records[$jobId])) {
            return;
        }

        $record = $this->records[$jobId];

        $this->records[$jobId] = new JobRecord(
            id: $record->id,
            queue: $record->queue,
            jobClass: $record->jobClass,
            payload: $record->payload,
            attempts: $record->attempts,
            status: JobRecordStatus::Failed,
            createdAt: $record->createdAt,
            availableAt: $record->availableAt,
        );
    }

    #[Override]
    public function size(string $queue): int
    {
        return count(array_filter(
            $this->records,
            static fn(JobRecord $r): bool => $r->queue === $queue
                && $r->status === JobRecordStatus::Pending,
        ));
    }

    #[Override]
    public function purge(string $queue): int
    {
        $before = count($this->records);

        $this->records = array_filter(
            $this->records,
            static fn(JobRecord $r): bool => $r->queue !== $queue,
        );

        return $before - count($this->records);
    }

    /**
     * @return list<JobRecord>
     */
    #[Override]
    public function findByStatus(JobRecordStatus $status): array
    {
        return array_values(array_filter(
            $this->records,
            static fn(JobRecord $r): bool => $r->status === $status,
        ));
    }

    /**
     * Return all stored records for test inspection.
     *
     * @return list<JobRecord>
     */
    public function getAll(): array
    {
        return array_values($this->records);
    }
}
