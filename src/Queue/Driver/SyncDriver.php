<?php

declare(strict_types=1);

namespace Pulsar\Queue\Driver;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Queue\Exception\QueueException;
use Pulsar\Queue\JobContext;
use Pulsar\Queue\JobRecord;
use Pulsar\Queue\JobRecordStatus;
use Pulsar\Queue\QueueableInterface;
use Pulsar\Queue\QueueDriverInterface;
use Random\Engine\Secure;
use Random\RandomException;
use Random\Randomizer;

use function bin2hex;
use function class_exists;

/**
 * Synchronous queue driver that executes jobs immediately in-process.
 *
 * Useful for local development and testing where deferred execution
 * is not needed. Jobs are executed during the push() call and never
 * actually stored in a queue.
 */
#[Internal(reason: 'Implementation detail; use QueueDriverInterface contract')]
final readonly class SyncDriver implements QueueDriverInterface
{
    private Randomizer $randomizer;

    public function __construct(?Randomizer $randomizer = null)
    {
        $this->randomizer = $randomizer ?? new Randomizer(new Secure());
    }

    /**
     * @throws RandomException If random byte generation fails
     * @throws QueueException If the job class does not exist or is not queueable
     */
    #[Override]
    public function push(string $queue, string $jobClass, string $payload, int $delay = 0): string
    {
        $jobId = bin2hex($this->randomizer->getBytes(16));

        if (!class_exists($jobClass)) {
            throw QueueException::serializationFailed($jobClass);
        }

        /** @var class-string $jobClassName */
        $jobClassName = $jobClass;
        $job = new $jobClassName();

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

    #[Override]
    public function pop(string $queue): ?JobRecord
    {
        return null;
    }

    #[Override]
    public function acknowledge(string $jobId): void
    {
        // No-op: sync driver executes immediately during push.
    }

    #[Override]
    public function reject(string $jobId, string $reason): void
    {
        // No-op: sync driver executes immediately during push.
    }

    #[Override]
    public function size(string $queue): int
    {
        return 0;
    }

    #[Override]
    public function purge(string $queue): int
    {
        return 0;
    }

    /**
     * @return list<JobRecord>
     */
    #[Override]
    public function findByStatus(JobRecordStatus $status): array
    {
        return [];
    }
}
