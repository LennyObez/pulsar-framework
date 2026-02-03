<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Queue;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Pulsar\Queue\JobContext;
use Pulsar\Queue\JobRecord;
use Pulsar\Queue\JobRecordStatus;
use Pulsar\Queue\QueueableInterface;
use Pulsar\Queue\QueueDriverInterface;
use Pulsar\Queue\Worker;
use Pulsar\Queue\WorkerOptions;
use Pulsar\Queue\WorkerStatus;
use RuntimeException;

#[CoversClass(Worker::class)]
final class WorkerTest extends TestCase
{
    #[Test]
    public function it_starts_in_stopped_status(): void
    {
        $driver = $this->createStub(QueueDriverInterface::class);
        $options = new WorkerOptions();
        $worker = new Worker($driver, $options);

        self::assertSame(WorkerStatus::Stopped, $worker->status);
    }

    #[Test]
    public function it_processes_next_job_successfully(): void
    {
        $record = new JobRecord(
            id: 'job-001',
            queue: 'default',
            jobClass: WorkerTestSuccessJob::class,
            payload: '{}',
            attempts: 1,
            status: JobRecordStatus::Processing,
            createdAt: 1700000000,
            availableAt: 1700000000,
        );

        $driver = $this->createMock(QueueDriverInterface::class);
        $driver
            ->expects(self::once())
            ->method('pop')
            ->with('default')
            ->willReturn($record);
        $driver
            ->expects(self::once())
            ->method('acknowledge')
            ->with('job-001');

        $options = new WorkerOptions();
        $worker = new Worker($driver, $options);

        $processed = $worker->processNextJob('default');

        self::assertTrue($processed);
        self::assertTrue(WorkerTestSuccessJob::$handled);
    }

    #[Test]
    public function it_returns_false_when_queue_is_empty(): void
    {
        $driver = $this->createMock(QueueDriverInterface::class);
        $driver
            ->expects(self::once())
            ->method('pop')
            ->with('default')
            ->willReturn(null);

        $options = new WorkerOptions();
        $worker = new Worker($driver, $options);

        $processed = $worker->processNextJob('default');

        self::assertFalse($processed);
    }

    #[Test]
    public function it_rejects_job_when_execution_throws(): void
    {
        $record = new JobRecord(
            id: 'job-fail',
            queue: 'default',
            jobClass: WorkerTestFailingJob::class,
            payload: '{}',
            attempts: 1,
            status: JobRecordStatus::Processing,
            createdAt: 1700000000,
            availableAt: 1700000000,
        );

        $driver = $this->createMock(QueueDriverInterface::class);
        $driver
            ->expects(self::once())
            ->method('pop')
            ->willReturn($record);
        $driver
            ->expects(self::never())
            ->method('acknowledge');
        $driver
            ->expects(self::once())
            ->method('reject')
            ->with('job-fail', 'Intentional failure');

        $options = new WorkerOptions();
        $worker = new Worker($driver, $options);

        $processed = $worker->processNextJob('default');

        self::assertTrue($processed);
    }

    #[Test]
    public function it_rejects_job_when_class_does_not_exist(): void
    {
        $record = new JobRecord(
            id: 'job-bad-class',
            queue: 'default',
            jobClass: 'NonExistent\\Job\\Class',
            payload: '{}',
            attempts: 1,
            status: JobRecordStatus::Processing,
            createdAt: 1700000000,
            availableAt: 1700000000,
        );

        $driver = $this->createMock(QueueDriverInterface::class);
        $driver
            ->expects(self::once())
            ->method('pop')
            ->willReturn($record);
        $driver
            ->expects(self::once())
            ->method('reject')
            ->with('job-bad-class', self::stringContains('serialize'));

        $options = new WorkerOptions();
        $worker = new Worker($driver, $options);

        $worker->processNextJob('default');
    }

    #[Test]
    public function it_rejects_job_when_class_is_not_queueable(): void
    {
        $record = new JobRecord(
            id: 'job-not-queueable',
            queue: 'default',
            jobClass: WorkerTestNonQueueableJob::class,
            payload: '{}',
            attempts: 1,
            status: JobRecordStatus::Processing,
            createdAt: 1700000000,
            availableAt: 1700000000,
        );

        $driver = $this->createMock(QueueDriverInterface::class);
        $driver
            ->expects(self::once())
            ->method('pop')
            ->willReturn($record);
        $driver
            ->expects(self::once())
            ->method('reject')
            ->with('job-not-queueable', self::stringContains('serialize'));

        $options = new WorkerOptions();
        $worker = new Worker($driver, $options);

        $worker->processNextJob('default');
    }

    #[Test]
    public function it_stops_on_request(): void
    {
        $driver = $this->createStub(QueueDriverInterface::class);
        $options = new WorkerOptions();
        $worker = new Worker($driver, $options);

        $worker->stop();

        self::assertSame(WorkerStatus::Stopping, $worker->status);
    }

    #[Test]
    public function it_runs_and_stops_after_max_jobs(): void
    {
        $record = new JobRecord(
            id: 'run-job-1',
            queue: 'default',
            jobClass: WorkerTestSuccessJob::class,
            payload: '{}',
            attempts: 1,
            status: JobRecordStatus::Processing,
            createdAt: 1700000000,
            availableAt: 1700000000,
        );

        $driver = $this->createStub(QueueDriverInterface::class);
        $driver
            ->method('pop')
            ->willReturn($record);
        $driver
            ->method('acknowledge');

        $options = new WorkerOptions(maxJobs: 2, sleepMs: 1);
        $worker = new Worker($driver, $options);

        $worker->run('default');

        self::assertSame(WorkerStatus::Stopped, $worker->status);
    }

    #[Test]
    public function it_logs_worker_start_and_stop(): void
    {
        $record = new JobRecord(
            id: 'log-run-job',
            queue: 'default',
            jobClass: WorkerTestSuccessJob::class,
            payload: '{}',
            attempts: 1,
            status: JobRecordStatus::Processing,
            createdAt: 1700000000,
            availableAt: 1700000000,
        );

        $driver = $this->createStub(QueueDriverInterface::class);
        $driver->method('pop')->willReturn($record);

        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects(self::atLeast(2))
            ->method('info')
            ->with(self::logicalOr(
                self::stringContains('started'),
                self::stringContains('stopped'),
                self::stringContains('recycling'),
            ));

        $options = new WorkerOptions(maxJobs: 1, sleepMs: 1);
        $worker = new Worker($driver, $options, $logger);

        $worker->run('default');
    }

    #[Test]
    public function it_logs_successful_job_processing(): void
    {
        $record = new JobRecord(
            id: 'log-job',
            queue: 'default',
            jobClass: WorkerTestSuccessJob::class,
            payload: '{}',
            attempts: 1,
            status: JobRecordStatus::Processing,
            createdAt: 1700000000,
            availableAt: 1700000000,
        );

        $driver = $this->createStub(QueueDriverInterface::class);
        $driver->method('pop')->willReturn($record);

        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects(self::atLeast(2))
            ->method('debug')
            ->with(self::logicalOr(
                self::stringContains('Processing job'),
                self::stringContains('completed successfully'),
            ));

        $options = new WorkerOptions();
        $worker = new Worker($driver, $options, $logger);

        $worker->processNextJob('default');
    }

    #[Test]
    public function it_logs_failed_job_processing(): void
    {
        $record = new JobRecord(
            id: 'log-fail-job',
            queue: 'default',
            jobClass: WorkerTestFailingJob::class,
            payload: '{}',
            attempts: 1,
            status: JobRecordStatus::Processing,
            createdAt: 1700000000,
            availableAt: 1700000000,
        );

        $driver = $this->createStub(QueueDriverInterface::class);
        $driver->method('pop')->willReturn($record);

        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects(self::once())
            ->method('error')
            ->with(self::stringContains('failed'));

        $options = new WorkerOptions();
        $worker = new Worker($driver, $options, $logger);

        $worker->processNextJob('default');
    }

    #[Test]
    public function it_provides_correct_job_context(): void
    {
        WorkerTestContextCapture::$capturedContext = null;

        $record = new JobRecord(
            id: 'ctx-001',
            queue: 'reports',
            jobClass: WorkerTestContextCapture::class,
            payload: '{}',
            attempts: 2,
            status: JobRecordStatus::Processing,
            createdAt: 1700000000,
            availableAt: 1700000000,
        );

        $driver = $this->createStub(QueueDriverInterface::class);
        $driver->method('pop')->willReturn($record);

        $options = new WorkerOptions();
        $worker = new Worker($driver, $options);

        $worker->processNextJob('reports');

        self::assertNotNull(WorkerTestContextCapture::$capturedContext);
        self::assertSame('ctx-001', WorkerTestContextCapture::$capturedContext->jobId);
        self::assertSame('reports', WorkerTestContextCapture::$capturedContext->queue);
        self::assertSame(2, WorkerTestContextCapture::$capturedContext->attempt);
        self::assertSame(3, WorkerTestContextCapture::$capturedContext->maxAttempts);
    }

    protected function tearDown(): void
    {
        WorkerTestSuccessJob::$handled = false;
        WorkerTestContextCapture::$capturedContext = null;
    }
}

/**
 * @internal Test double — successful job
 */
final class WorkerTestSuccessJob implements QueueableInterface
{
    public static bool $handled = false;

    public function handle(JobContext $context): void
    {
        self::$handled = true;
    }

    public function queue(): string
    {
        return 'default';
    }

    public function maxAttempts(): int
    {
        return 3;
    }

    public function timeout(): int
    {
        return 60;
    }
}

/**
 * @internal Test double — always throws
 */
final class WorkerTestFailingJob implements QueueableInterface
{
    public function handle(JobContext $context): void
    {
        throw new RuntimeException('Intentional failure');
    }

    public function queue(): string
    {
        return 'default';
    }

    public function maxAttempts(): int
    {
        return 3;
    }

    public function timeout(): int
    {
        return 60;
    }
}

/**
 * @internal Test double — not implementing QueueableInterface
 */
final class WorkerTestNonQueueableJob
{
    public function handle(): void {}
}

/**
 * @internal Test double — captures context
 */
final class WorkerTestContextCapture implements QueueableInterface
{
    public static ?JobContext $capturedContext = null;

    public function handle(JobContext $context): void
    {
        self::$capturedContext = $context;
    }

    public function queue(): string
    {
        return 'default';
    }

    public function maxAttempts(): int
    {
        return 3;
    }

    public function timeout(): int
    {
        return 60;
    }
}
