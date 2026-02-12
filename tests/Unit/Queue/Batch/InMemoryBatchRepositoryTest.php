<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Queue\Batch;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Queue\Batch\InMemoryBatchRepository;
use Pulsar\Queue\Batch\JobBatch;
use Pulsar\Queue\Exception\QueueException;

#[CoversClass(InMemoryBatchRepository::class)]
final class InMemoryBatchRepositoryTest extends TestCase
{
    private InMemoryBatchRepository $repository;

    protected function setUp(): void
    {
        $this->repository = new InMemoryBatchRepository();
    }

    #[Test]
    public function findReturnsNullForNonExistentBatch(): void
    {
        self::assertNull($this->repository->find('nonexistent'));
    }

    #[Test]
    public function storeAndFindReturnsBatch(): void
    {
        $batch = $this->createBatch('batch-001');

        $this->repository->store($batch);

        $found = $this->repository->find('batch-001');
        self::assertNotNull($found);
        self::assertSame('batch-001', $found->id);
    }

    #[Test]
    public function markJobCompleteDecrementsPendingCount(): void
    {
        $batch = $this->createBatch('batch-002', pendingJobs: 3);
        $this->repository->store($batch);

        $updated = $this->repository->markJobComplete('batch-002');

        self::assertSame(2, $updated->pendingJobs);
        self::assertNull($updated->finishedAt);
    }

    #[Test]
    public function markJobCompleteSetsFinishedAtWhenAllDone(): void
    {
        $batch = $this->createBatch('batch-003', pendingJobs: 1);
        $this->repository->store($batch);

        $updated = $this->repository->markJobComplete('batch-003');

        self::assertSame(0, $updated->pendingJobs);
        self::assertNotNull($updated->finishedAt);
    }

    #[Test]
    public function markJobCompleteThrowsForMissingBatch(): void
    {
        $this->expectException(QueueException::class);

        $this->repository->markJobComplete('nonexistent');
    }

    #[Test]
    public function markJobFailedIncrementsFailedAndDecrementsPending(): void
    {
        $batch = $this->createBatch('batch-004', pendingJobs: 3, failedJobs: 0);
        $this->repository->store($batch);

        $updated = $this->repository->markJobFailed('batch-004');

        self::assertSame(2, $updated->pendingJobs);
        self::assertSame(1, $updated->failedJobs);
        self::assertNull($updated->finishedAt);
    }

    #[Test]
    public function markJobFailedSetsFinishedAtWhenAllDone(): void
    {
        $batch = $this->createBatch('batch-005', pendingJobs: 1, failedJobs: 0);
        $this->repository->store($batch);

        $updated = $this->repository->markJobFailed('batch-005');

        self::assertSame(0, $updated->pendingJobs);
        self::assertSame(1, $updated->failedJobs);
        self::assertNotNull($updated->finishedAt);
    }

    #[Test]
    public function markJobFailedThrowsForMissingBatch(): void
    {
        $this->expectException(QueueException::class);

        $this->repository->markJobFailed('nonexistent');
    }

    #[Test]
    public function cancelMarksBatchAsCancelled(): void
    {
        $batch = $this->createBatch('batch-006');
        $this->repository->store($batch);

        $this->repository->cancel('batch-006');

        $found = $this->repository->find('batch-006');
        self::assertNotNull($found);
        self::assertTrue($found->cancelled);
    }

    #[Test]
    public function cancelThrowsForMissingBatch(): void
    {
        $this->expectException(QueueException::class);

        $this->repository->cancel('nonexistent');
    }

    #[Test]
    public function pruneRemovesFinishedBatchesBeforeTimestamp(): void
    {
        $finished = new JobBatch('b-old', 'old', 5, 0, 0, false, false, 1700000000, 1700000050);
        $recent = new JobBatch('b-recent', 'recent', 5, 0, 0, false, false, 1700000100, 1700000200);
        $unfinished = new JobBatch('b-pending', 'pending', 5, 3, 0, false, false, 1700000000, null);

        $this->repository->store($finished);
        $this->repository->store($recent);
        $this->repository->store($unfinished);

        $pruned = $this->repository->prune(1700000100);

        self::assertSame(1, $pruned);
        self::assertNull($this->repository->find('b-old'));
        self::assertNotNull($this->repository->find('b-recent'));
        self::assertNotNull($this->repository->find('b-pending'));
    }

    #[Test]
    public function pruneReturnsZeroWhenNothingToPrune(): void
    {
        self::assertSame(0, $this->repository->prune(1700000000));
    }

    private function createBatch(
        string $id = 'batch-test',
        int $pendingJobs = 5,
        int $failedJobs = 0,
    ): JobBatch {
        return new JobBatch(
            id: $id,
            name: 'test-batch',
            totalJobs: 10,
            pendingJobs: $pendingJobs,
            failedJobs: $failedJobs,
            cancelled: false,
            allowFailures: false,
            createdAt: 1700000000,
            finishedAt: null,
        );
    }
}
