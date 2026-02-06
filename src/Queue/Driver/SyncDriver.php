<?php

declare(strict_types=1);

namespace Pulsar\Queue\Driver;

use function bin2hex;
use function class_exists;

use Pulsar\Api\Internal;
use Pulsar\Queue\Exception\QueueException;
use Pulsar\Queue\JobContext;
use Pulsar\Queue\JobRecord;
use Pulsar\Queue\JobRecordStatus;
use Pulsar\Queue\QueueableInterface;
use Pulsar\Queue\QueueDriverInterface;

use function random_bytes;

/**
 * Synchronous queue driver that executes jobs immediately in-process.
 *
 * Useful for local development and testing where deferred execution
 * is not needed. Jobs are executed during the push() call and never
 * actually stored in a queue.
 */
#[Internal(reason: 'Implementation detail — use QueueDriverInterface contract')]
final class SyncDriver implements QueueDriverInterface
{
    /**
     * @throws \Random\RandomException If random byte generation fails
     * @throws QueueException If the job class does not exist or is not queueable
     */
    public function push(string $queue, string $jobClass, string $payload, int $delay = 0): string
    {
        $jobId = bin2hex(random_bytes(16));

        if (!class_exists($jobClass)) {
            throw QueueException::serializationFailed($jobClass);
        }

        /** @var object $job */
        $job = new $jobClass();

        if (!$job instanceof QueueableInterface) {
            throw QueueException::serializationFailed($jobClass);
        }

        $context = new JobContext(
            jobId: $jobId,
            queue: $queue,
            attempt: 1,
            maxAttempts: $job->maxAttempts(),
        );

        try {
            $job->handle($context);
        } finally {
            unset($job, $context);
        }

        return $jobId;
    }

    public function pop(string $queue): ?JobRecord
    {
        return null;
    }

    public function acknowledge(string $jobId): void
    {
        // No-op: sync driver executes immediately during push.
    }

    public function reject(string $jobId, string $reason): void
    {
        // No-op: sync driver executes immediately during push.
    }

    public function size(string $queue): int
    {
        return 0;
    }

    public function purge(string $queue): int
    {
        return 0;
    }

    /**
     * @return list<JobRecord>
     */
    public function findByStatus(JobRecordStatus $status): array
    {
        return [];
    }
}
