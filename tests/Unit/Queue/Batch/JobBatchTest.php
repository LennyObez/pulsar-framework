<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Queue\Batch;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Queue\Batch\JobBatch;

#[CoversClass(JobBatch::class)]
final class JobBatchTest extends TestCase
{
    #[Test]
    public function constructorStoresAllProperties(): void
    {
        $batch = new JobBatch(
            id: 'batch-001',
            name: 'import-users',
            totalJobs: 10,
            pendingJobs: 5,
            failedJobs: 1,
            cancelled: false,
            allowFailures: true,
            createdAt: 1700000000,
            finishedAt: null,
        );

        self::assertSame('batch-001', $batch->id);
        self::assertSame('import-users', $batch->name);
        self::assertSame(10, $batch->totalJobs);
        self::assertSame(5, $batch->pendingJobs);
        self::assertSame(1, $batch->failedJobs);
        self::assertFalse($batch->cancelled);
        self::assertTrue($batch->allowFailures);
        self::assertSame(1700000000, $batch->createdAt);
        self::assertNull($batch->finishedAt);
    }

    #[Test]
    public function isFinishedReturnsTrueWhenFinishedAtIsSet(): void
    {
        $batch = $this->createBatch(finishedAt: 1700000100);

        self::assertTrue($batch->isFinished());
    }

    #[Test]
    public function isFinishedReturnsFalseWhenFinishedAtIsNull(): void
    {
        $batch = $this->createBatch(finishedAt: null);

        self::assertFalse($batch->isFinished());
    }

    #[Test]
    public function isSuccessfulReturnsTrueWhenFinishedWithNoFailures(): void
    {
        $batch = $this->createBatch(failedJobs: 0, finishedAt: 1700000100);

        self::assertTrue($batch->isSuccessful());
    }

    #[Test]
    public function isSuccessfulReturnsFalseWhenNotFinished(): void
    {
        $batch = $this->createBatch(failedJobs: 0, finishedAt: null);

        self::assertFalse($batch->isSuccessful());
    }

    #[Test]
    public function isSuccessfulReturnsFalseWhenFinishedWithFailures(): void
    {
        $batch = $this->createBatch(failedJobs: 3, finishedAt: 1700000100);

        self::assertFalse($batch->isSuccessful());
    }

    #[Test]
    #[DataProvider('processedJobsProvider')]
    public function processedJobsCalculatesCorrectly(int $total, int $pending, int $expected): void
    {
        $batch = $this->createBatch(totalJobs: $total, pendingJobs: $pending);

        self::assertSame($expected, $batch->processedJobs());
    }

    /**
     * @return iterable<string, array{int, int, int}>
     */
    public static function processedJobsProvider(): iterable
    {
        yield 'all pending' => [10, 10, 0];
        yield 'half processed' => [10, 5, 5];
        yield 'all processed' => [10, 0, 10];
        yield 'single job done' => [1, 0, 1];
    }

    private function createBatch(
        int $totalJobs = 10,
        int $pendingJobs = 5,
        int $failedJobs = 0,
        ?int $finishedAt = null,
    ): JobBatch {
        return new JobBatch(
            id: 'batch-test',
            name: 'test-batch',
            totalJobs: $totalJobs,
            pendingJobs: $pendingJobs,
            failedJobs: $failedJobs,
            cancelled: false,
            allowFailures: false,
            createdAt: 1700000000,
            finishedAt: $finishedAt,
        );
    }
}
