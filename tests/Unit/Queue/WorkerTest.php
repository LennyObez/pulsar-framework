<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Queue;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Pulsar\Queue\Envelope\BackoffStrategy;
use Pulsar\Queue\Envelope\EnvelopeSerializer;
use Pulsar\Queue\Envelope\JobEnvelope;
use Pulsar\Queue\JobContext;
use Pulsar\Queue\JobRecord;
use Pulsar\Queue\JobRecordStatus;
use Pulsar\Queue\QueueableInterface;
use Pulsar\Queue\QueueDriverInterface;
use Pulsar\Queue\Worker;
use Pulsar\Queue\WorkerOptions;
use Pulsar\Queue\WorkerStatus;
use RuntimeException;

use function is_int;
use function is_string;
use function time;

#[CoversClass(Worker::class)]
final class WorkerTest extends TestCase
{
    private EnvelopeSerializer $serializer;

    protected function setUp(): void
    {
        $this->serializer = new EnvelopeSerializer();
    }

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
        WorkerTestSuccessJob::$handled = false;

        $record = $this->makeRecord('job-001', 'default', WorkerTestSuccessJob::class);

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
    public function it_dead_letters_job_when_max_attempts_exceeded(): void
    {
        $record = $this->makeRecord('job-fail', 'default', WorkerTestFailingJob::class, attempt: 3, maxAttempts: 3);

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
            ->with('job-fail', self::stringContains('Intentional failure'));

        $options = new WorkerOptions();
        $worker = new Worker($driver, $options);

        $processed = $worker->processNextJob('default');

        self::assertTrue($processed);
    }

    #[Test]
    public function it_retries_job_when_attempts_remain(): void
    {
        $record = $this->makeRecord('job-retry', 'default', WorkerTestFailingJob::class, attempt: 1, maxAttempts: 3);

        $driver = $this->createMock(QueueDriverInterface::class);
        $driver
            ->expects(self::once())
            ->method('pop')
            ->willReturn($record);
        $driver
            ->expects(self::once())
            ->method('push')
            ->with(
                'default',
                WorkerTestFailingJob::class,
                self::callback(static fn(mixed $v): bool => is_string($v)),
                self::callback(static fn(mixed $v): bool => is_int($v)),
            );
        $driver
            ->expects(self::once())
            ->method('acknowledge')
            ->with('job-retry');

        $options = new WorkerOptions();
        $worker = new Worker($driver, $options);

        $processed = $worker->processNextJob('default');

        self::assertTrue($processed);
    }

    #[Test]
    public function it_rejects_on_invalid_envelope_payload(): void
    {
        $record = new JobRecord(
            id: 'job-bad-envelope',
            queue: 'default',
            jobClass: 'App\\Jobs\\Test',
            payload: 'not-valid-json',
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
            ->with('job-bad-envelope', self::stringContains('Envelope deserialization failed'));

        $options = new WorkerOptions();
        $worker = new Worker($driver, $options);

        $worker->processNextJob('default');
    }

    #[Test]
    public function it_rejects_on_unknown_job_class_at_max_attempts(): void
    {
        $record = $this->makeRecord('job-bad-class', 'default', 'NonExistent\\Job\\Class', attempt: 3, maxAttempts: 3);

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
    public function it_rejects_on_non_queueable_class_at_max_attempts(): void
    {
        $record = $this->makeRecord('job-not-q', 'default', WorkerTestNonQueueableJob::class, attempt: 3, maxAttempts: 3);

        $driver = $this->createMock(QueueDriverInterface::class);
        $driver
            ->expects(self::once())
            ->method('pop')
            ->willReturn($record);
        $driver
            ->expects(self::once())
            ->method('reject')
            ->with('job-not-q', self::stringContains('serialize'));

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
        $record = $this->makeRecord('run-job-1', 'default', WorkerTestSuccessJob::class);

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
        $record = $this->makeRecord('log-run-job', 'default', WorkerTestSuccessJob::class);

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
        $record = $this->makeRecord('log-job', 'default', WorkerTestSuccessJob::class);

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
        $record = $this->makeRecord('log-fail-job', 'default', WorkerTestFailingJob::class, attempt: 3, maxAttempts: 3);

        $driver = $this->createStub(QueueDriverInterface::class);
        $driver->method('pop')->willReturn($record);

        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects(self::atLeast(1))
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

        $record = $this->makeRecord('ctx-001', 'reports', WorkerTestContextCapture::class, attempt: 2, maxAttempts: 5);

        $driver = $this->createStub(QueueDriverInterface::class);
        $driver->method('pop')->willReturn($record);

        $options = new WorkerOptions();
        $worker = new Worker($driver, $options);

        $worker->processNextJob('reports');

        self::assertNotNull(WorkerTestContextCapture::$capturedContext);
        self::assertSame('ctx-001', WorkerTestContextCapture::$capturedContext->jobId);
        self::assertSame('reports', WorkerTestContextCapture::$capturedContext->queue);
        self::assertSame(2, WorkerTestContextCapture::$capturedContext->attempt);
        self::assertSame(5, WorkerTestContextCapture::$capturedContext->maxAttempts);
    }

    /**
     * Build a JobRecord with a properly serialized envelope as payload.
     */
    private function makeRecord(
        string $id,
        string $queue,
        string $jobClass,
        int $attempt = 1,
        int $maxAttempts = 3,
    ): JobRecord {
        $envelope = new JobEnvelope(
            id: $id,
            jobClass: $jobClass,
            payload: '{}',
            queue: $queue,
            idempotencyKey: '',
            correlationId: '',
            traceId: null,
            spanId: null,
            schemaVersion: 1,
            keyId: null,
            retryMaxAttempts: $maxAttempts,
            retryBackoffStrategy: BackoffStrategy::Exponential,
            retryDelayMs: 1000,
            tenantId: null,
            subjectId: null,
            batchId: null,
            chainIndex: null,
            attempt: $attempt,
            dispatchedAt: time(),
            encrypted: false,
            metadata: [],
        );

        return new JobRecord(
            id: $id,
            queue: $queue,
            jobClass: $jobClass,
            payload: $this->serializer->serialize($envelope),
            attempts: $attempt,
            status: JobRecordStatus::Processing,
            createdAt: 1700000000,
            availableAt: 1700000000,
        );
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
