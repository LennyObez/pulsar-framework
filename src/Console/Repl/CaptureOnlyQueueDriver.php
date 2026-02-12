<?php

declare(strict_types=1);

namespace Pulsar\Console\Repl;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Queue\JobRecord;
use Pulsar\Queue\JobRecordStatus;
use Pulsar\Queue\QueueDriverInterface;

use function bin2hex;
use function count;
use function random_bytes;

/**
 * Queue driver decorator for REPL safe mode.
 *
 * Captures push calls without dispatching. Read operations (size, findByStatus)
 * delegate to the inner driver. Mutating operations (pop, acknowledge, reject,
 * purge) are blocked.
 */
#[Internal]
final class CaptureOnlyQueueDriver implements QueueDriverInterface
{
    /** @var list<array{queue: string, jobClass: string, payload: string, delay: int}> */
    private array $captured = [];

    public function __construct(
        private readonly QueueDriverInterface $inner,
    ) {}

    /**
     * @throws ReplSafeModeException Never thrown — captures the push without dispatching
     */
    #[Override]
    public function push(string $queue, string $jobClass, string $payload, int $delay = 0): string
    {
        $fakeId = 'repl_' . bin2hex(random_bytes(8));

        $this->captured[] = [
            'queue' => $queue,
            'jobClass' => $jobClass,
            'payload' => $payload,
            'delay' => $delay,
        ];

        return $fakeId;
    }

    /**
     * @throws ReplSafeModeException Always — pop is a destructive read (removes the job from the queue)
     */
    #[Override]
    public function pop(string $queue): ?JobRecord
    {
        throw ReplSafeModeException::operationBlocked('queue:pop');
    }

    /**
     * @throws ReplSafeModeException Always — blocked in safe mode
     */
    #[Override]
    public function acknowledge(string $jobId): void
    {
        throw ReplSafeModeException::operationBlocked('queue:acknowledge');
    }

    /**
     * @throws ReplSafeModeException Always — blocked in safe mode
     */
    #[Override]
    public function reject(string $jobId, string $reason): void
    {
        throw ReplSafeModeException::operationBlocked('queue:reject');
    }

    #[Override]
    public function size(string $queue): int
    {
        return $this->inner->size($queue);
    }

    /**
     * @throws ReplSafeModeException Always — blocked in safe mode
     */
    #[Override]
    public function purge(string $queue): int
    {
        throw ReplSafeModeException::operationBlocked('queue:purge');
    }

    /**
     * @return list<JobRecord>
     */
    #[Override]
    public function findByStatus(JobRecordStatus $status): array
    {
        return $this->inner->findByStatus($status);
    }

    /**
     * Get all captured push calls that would have been dispatched.
     *
     * @return list<array{queue: string, jobClass: string, payload: string, delay: int}>
     */
    public function getCaptured(): array
    {
        return $this->captured;
    }

    /**
     * Get the number of captured push calls.
     */
    public function getCapturedCount(): int
    {
        return count($this->captured);
    }
}
