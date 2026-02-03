<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Queue;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Queue\DeadLetterQueue;
use Pulsar\Queue\Exception\QueueException;
use Pulsar\Queue\FailedJob;
use Pulsar\Queue\JobRecord;
use Pulsar\Queue\JobRecordStatus;
use Pulsar\Queue\QueueDriverInterface;

#[CoversClass(DeadLetterQueue::class)]
final class DeadLetterQueueTest extends TestCase
{
    private QueueDriverInterface&\PHPUnit\Framework\MockObject\Stub $driver;
    private DeadLetterQueue $dlq;

    protected function setUp(): void
    {
        $this->driver = $this->createStub(QueueDriverInterface::class);
        $this->dlq = new DeadLetterQueue($this->driver);
    }

    #[Test]
    public function it_stores_a_failed_job(): void
    {
        $record = $this->makeRecord('job-001', 'emails', 'App\\Jobs\\SendEmail', '{}', 3);

        $this->dlq->store($record, 'Connection refused');

        $listed = $this->dlq->list();
        self::assertCount(1, $listed);
        self::assertInstanceOf(FailedJob::class, $listed[0]);
        self::assertSame('job-001', $listed[0]->id);
        self::assertSame('emails', $listed[0]->queue);
        self::assertSame('App\\Jobs\\SendEmail', $listed[0]->jobClass);
        self::assertSame('{}', $listed[0]->payload);
        self::assertSame('Connection refused', $listed[0]->exception);
        self::assertSame(3, $listed[0]->attempts);
    }

    #[Test]
    public function it_stores_multiple_failed_jobs(): void
    {
        $this->dlq->store($this->makeRecord('j1', 'default', 'App\\Jobs\\A', '{}', 1), 'Error A');
        $this->dlq->store($this->makeRecord('j2', 'default', 'App\\Jobs\\B', '{}', 2), 'Error B');
        $this->dlq->store($this->makeRecord('j3', 'emails', 'App\\Jobs\\C', '{}', 3), 'Error C');

        self::assertCount(3, $this->dlq->list());
    }

    #[Test]
    public function it_retries_a_single_failed_job(): void
    {
        $driver = $this->createMock(QueueDriverInterface::class);
        $dlq = new DeadLetterQueue($driver);

        $dlq->store(
            $this->makeRecord('retry-001', 'emails', 'App\\Jobs\\SendEmail', '{"to":"a@b.com"}', 2),
            'Timeout',
        );

        $driver
            ->expects(self::once())
            ->method('push')
            ->with('emails', 'App\\Jobs\\SendEmail', '{"to":"a@b.com"}');

        $dlq->retry('retry-001');

        self::assertCount(0, $dlq->list());
    }

    #[Test]
    public function it_throws_when_retrying_nonexistent_failed_job(): void
    {
        $this->expectException(QueueException::class);
        $this->expectExceptionMessage('not found');

        $this->dlq->retry('nonexistent-id');
    }

    #[Test]
    public function it_retries_all_failed_jobs(): void
    {
        $driver = $this->createMock(QueueDriverInterface::class);
        $dlq = new DeadLetterQueue($driver);

        $dlq->store($this->makeRecord('ra1', 'default', 'App\\Jobs\\A', '{}', 1), 'Err');
        $dlq->store($this->makeRecord('ra2', 'emails', 'App\\Jobs\\B', '{}', 2), 'Err');
        $dlq->store($this->makeRecord('ra3', 'default', 'App\\Jobs\\C', '{}', 3), 'Err');

        $driver
            ->expects(self::exactly(3))
            ->method('push');

        $count = $dlq->retryAll();

        self::assertSame(3, $count);
        self::assertCount(0, $dlq->list());
    }

    #[Test]
    public function it_returns_zero_when_retrying_all_with_empty_dlq(): void
    {
        $count = $this->dlq->retryAll();

        self::assertSame(0, $count);
    }

    #[Test]
    public function it_purges_all_failed_jobs(): void
    {
        $this->dlq->store($this->makeRecord('p1', 'default', 'App\\Jobs\\A', '{}', 1), 'Err');
        $this->dlq->store($this->makeRecord('p2', 'default', 'App\\Jobs\\B', '{}', 2), 'Err');

        $purged = $this->dlq->purge();

        self::assertSame(2, $purged);
        self::assertCount(0, $this->dlq->list());
    }

    #[Test]
    public function it_returns_zero_when_purging_empty_dlq(): void
    {
        $purged = $this->dlq->purge();

        self::assertSame(0, $purged);
    }

    #[Test]
    public function it_lists_empty_dlq(): void
    {
        self::assertSame([], $this->dlq->list());
    }

    #[Test]
    public function it_removes_only_the_retried_job(): void
    {
        $this->dlq->store($this->makeRecord('keep-1', 'default', 'App\\Jobs\\A', '{}', 1), 'Err');
        $this->dlq->store($this->makeRecord('remove-1', 'default', 'App\\Jobs\\B', '{}', 2), 'Err');
        $this->dlq->store($this->makeRecord('keep-2', 'default', 'App\\Jobs\\C', '{}', 3), 'Err');

        $this->driver->method('push');

        $this->dlq->retry('remove-1');

        $remaining = $this->dlq->list();
        self::assertCount(2, $remaining);

        $remainingIds = array_map(static fn(FailedJob $fj): string => $fj->id, $remaining);
        self::assertContains('keep-1', $remainingIds);
        self::assertContains('keep-2', $remainingIds);
        self::assertNotContains('remove-1', $remainingIds);
    }

    #[Test]
    public function it_replaces_failed_job_with_same_id(): void
    {
        $record = $this->makeRecord('dup-1', 'default', 'App\\Jobs\\A', '{}', 1);

        $this->dlq->store($record, 'First failure');
        $this->dlq->store($record, 'Second failure');

        $listed = $this->dlq->list();
        self::assertCount(1, $listed);
        self::assertSame('Second failure', $listed[0]->exception);
    }

    #[Test]
    public function retry_dispatches_to_original_queue(): void
    {
        $driver = $this->createMock(QueueDriverInterface::class);
        $dlq = new DeadLetterQueue($driver);

        $dlq->store(
            $this->makeRecord('orig-q', 'high-priority', 'App\\Jobs\\Important', '{"urgent":true}', 5),
            'Network error',
        );

        $driver
            ->expects(self::once())
            ->method('push')
            ->with('high-priority', 'App\\Jobs\\Important', '{"urgent":true}');

        $dlq->retry('orig-q');
    }

    private function makeRecord(
        string $id,
        string $queue,
        string $jobClass,
        string $payload,
        int $attempts,
    ): JobRecord {
        return new JobRecord(
            id: $id,
            queue: $queue,
            jobClass: $jobClass,
            payload: $payload,
            attempts: $attempts,
            status: JobRecordStatus::Failed,
            createdAt: 1700000000,
            availableAt: 1700000000,
        );
    }
}
