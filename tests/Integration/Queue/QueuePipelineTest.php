<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Queue;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\QueueConfig;
use Pulsar\Config\QueueDriverType;
use Pulsar\Queue\DeadLetterQueue;
use Pulsar\Queue\Driver\InMemoryDriver;
use Pulsar\Queue\Driver\SyncDriver;
use Pulsar\Queue\Envelope\BackoffStrategy;
use Pulsar\Queue\Envelope\EnvelopeSerializer;
use Pulsar\Queue\Envelope\JobEnvelope;
use Pulsar\Queue\Exception\QueueException;
use Pulsar\Queue\JobContext;
use Pulsar\Queue\JobRecordStatus;
use Pulsar\Queue\QueueableInterface;
use Pulsar\Queue\QueueDriverInterface;
use Pulsar\Queue\QueueManager;
use Pulsar\Queue\Retry\QueueRetryPolicy;
use Pulsar\Queue\Retry\RetryDecision;
use Pulsar\Queue\Serialization\TypeRegistry;
use Pulsar\Queue\Worker;
use Pulsar\Queue\WorkerOptions;
use Pulsar\Queue\WorkerStatus;
use RuntimeException;

use function bin2hex;
use function random_bytes;
use function time;

/**
 * Integration tests for the queue pipeline.
 *
 * Exercises the full queue lifecycle: dispatch via QueueManager,
 * storage in drivers, processing via Worker, retry policy evaluation,
 * and dead-letter queue routing.
 */
#[CoversClass(QueueManager::class)]
#[CoversClass(SyncDriver::class)]
#[CoversClass(InMemoryDriver::class)]
#[CoversClass(Worker::class)]
#[CoversClass(QueueRetryPolicy::class)]
#[CoversClass(DeadLetterQueue::class)]
final class QueuePipelineTest extends TestCase
{
    private EnvelopeSerializer $envelopeSerializer;
    private TypeRegistry $typeRegistry;

    protected function setUp(): void
    {
        $this->envelopeSerializer = new EnvelopeSerializer();
        $this->typeRegistry = new TypeRegistry();
        $this->typeRegistry->register(PipelineSuccessJob::class);
        $this->typeRegistry->register(PipelineFailingJob::class);
        $this->typeRegistry->register(PipelineCountingJob::class);
        $this->typeRegistry->register(PipelineOrderTrackerFirst::class);
        $this->typeRegistry->register(PipelineOrderTrackerSecond::class);
        $this->typeRegistry->register(PipelineOrderTrackerThird::class);
    }

    private function createWorker(
        QueueDriverInterface $driver,
        WorkerOptions $options,
        ?QueueRetryPolicy $retryPolicy = null,
        ?DeadLetterQueue $dlq = null,
    ): Worker {
        return new Worker(
            driver: $driver,
            options: $options,
            typeRegistry: $this->typeRegistry,
            retryPolicy: $retryPolicy,
            deadLetterQueue: $dlq,
        );
    }

    // ---- SyncDriver happy path ----

    #[Test]
    public function syncDriverDispatchesAndHandlesJobImmediately(): void
    {
        PipelineSuccessJob::$handled = false;
        PipelineSuccessJob::$capturedContext = null;

        $config = new QueueConfig(
            enabled: true,
            driver: QueueDriverType::Sync,
            defaultQueue: 'default',
        );

        $manager = new QueueManager($config);
        $jobId = $manager->dispatch(PipelineSuccessJob::class, '{"key":"value"}');

        self::assertNotEmpty($jobId);
        self::assertTrue(PipelineSuccessJob::$handled, 'SyncDriver should execute the job immediately during dispatch');

        $context = PipelineSuccessJob::$capturedContext;
        /** @psalm-suppress TypeDoesNotContainType -- Psalm cannot trace static property mutation through SyncDriver::push() */
        self::assertInstanceOf(JobContext::class, $context);
        self::assertSame('default', $context->queue);
        self::assertSame(1, $context->attempt);
    }

    #[Test]
    public function syncDriverThrowsWhenJobClassDoesNotExist(): void
    {
        $config = new QueueConfig(
            enabled: true,
            driver: QueueDriverType::Sync,
            defaultQueue: 'default',
        );

        $manager = new QueueManager($config);

        $this->expectException(QueueException::class);
        $this->expectExceptionMessage('serialize');

        $manager->dispatch('NonExistent\\Job\\Class', '{}');
    }

    #[Test]
    public function syncDriverReportsQueueSizeAsZero(): void
    {
        $config = new QueueConfig(
            enabled: true,
            driver: QueueDriverType::Sync,
            defaultQueue: 'default',
        );

        $manager = new QueueManager($config);
        self::assertSame(0, $manager->size());
        self::assertSame(0, $manager->size('any-queue'));
    }

    // ---- InMemoryDriver + Worker processing ----

    #[Test]
    public function inMemoryDriverStoresAndWorkerProcessesJob(): void
    {
        PipelineSuccessJob::$handled = false;
        PipelineSuccessJob::$capturedContext = null;

        $driver = new InMemoryDriver();
        $config = new QueueConfig(
            enabled: true,
            driver: QueueDriverType::Memory,
            defaultQueue: 'default',
        );

        $manager = new QueueManager($config, $driver);
        $jobId = $manager->dispatch(PipelineSuccessJob::class, '{"data":"test"}');

        // Job should NOT have been handled yet (InMemoryDriver just stores it)
        self::assertFalse(PipelineSuccessJob::$handled);
        self::assertSame(1, $manager->size());

        // Worker processes it
        $options = new WorkerOptions(maxJobs: 1, sleepMs: 1, maxMemoryMb: 0);
        $worker = $this->createWorker($driver, $options);
        $worker->run('default');

        self::assertTrue(PipelineSuccessJob::$handled, 'Worker should have processed the job');

        $context = PipelineSuccessJob::$capturedContext;
        /** @psalm-suppress TypeDoesNotContainType -- Psalm cannot trace static property mutation through Worker::run() */
        self::assertInstanceOf(JobContext::class, $context);
        self::assertSame(1, $context->attempt);
    }

    #[Test]
    public function workerProcessesMultipleJobsInOrder(): void
    {
        PipelineOrderTracker::$order = [];

        $driver = new InMemoryDriver();

        // Push serialized envelopes (Worker now expects envelope payloads)
        $this->pushEnvelope($driver, 'default', PipelineOrderTrackerFirst::class, '{}');
        $this->pushEnvelope($driver, 'default', PipelineOrderTrackerSecond::class, '{}');
        $this->pushEnvelope($driver, 'default', PipelineOrderTrackerThird::class, '{}');

        $options = new WorkerOptions(maxJobs: 3, sleepMs: 1, maxMemoryMb: 0);
        $worker = $this->createWorker($driver, $options);
        $worker->run('default');

        /** @var list<string> $order */
        $order = PipelineOrderTracker::$order;
        self::assertSame(['first', 'second', 'third'], $order);
        self::assertSame(WorkerStatus::Stopped, $worker->status);
    }

    #[Test]
    public function workerAcknowledgesSuccessfulJobAndRemovesFromDriver(): void
    {
        PipelineSuccessJob::$handled = false;

        $driver = new InMemoryDriver();
        $this->pushEnvelope($driver, 'default', PipelineSuccessJob::class, '{}');

        self::assertSame(1, $driver->size('default'));

        $options = new WorkerOptions(maxJobs: 1, sleepMs: 1, maxMemoryMb: 0);
        $worker = $this->createWorker($driver, $options);
        $worker->run('default');

        // After successful processing, the job should be acknowledged (removed)
        self::assertCount(0, $driver->getAll());
    }

    // ---- Failure + retry ----

    #[Test]
    public function workerRejectsFailedJobAfterMaxAttempts(): void
    {
        $driver = new InMemoryDriver();

        // Push a failing job at attempt = maxAttempts (3). At attempt >= maxAttempts,
        // the retry policy returns DeadLetter, so the Worker will reject it.
        $this->pushEnvelope($driver, 'default', PipelineFailingJob::class, '{}', attempt: 3, retryMaxAttempts: 3);

        $options = new WorkerOptions(maxJobs: 1, sleepMs: 1, maxMemoryMb: 0);
        $worker = $this->createWorker($driver, $options);
        $worker->run('default');

        $failedJobs = $driver->findByStatus(JobRecordStatus::Failed);
        self::assertCount(1, $failedJobs);
    }

    #[Test]
    public function workerRetriesFailedJobWhenAttemptsRemain(): void
    {
        $driver = new InMemoryDriver();

        // Push a failing job at attempt 1 with maxAttempts 3.
        // The Worker should retry (push a new job with attempt=2) and acknowledge the original.
        $this->pushEnvelope($driver, 'default', PipelineFailingJob::class, '{}', attempt: 1, retryMaxAttempts: 3);

        $options = new WorkerOptions(maxJobs: 1, sleepMs: 1, maxMemoryMb: 0);
        $worker = $this->createWorker($driver, $options);
        $worker->run('default');

        // The original job should be acknowledged (removed), and a retry job should be pending
        $pending = $driver->findByStatus(JobRecordStatus::Pending);
        self::assertCount(1, $pending);

        // The retried job's payload should contain the incremented attempt
        $retryEnvelope = $this->envelopeSerializer->deserialize($pending[0]->payload);
        self::assertSame(2, $retryEnvelope->attempt);
    }

    #[Test]
    public function retryPolicyReturnsRetryWhenAttemptsRemain(): void
    {
        $policy = new QueueRetryPolicy(
            maxAttempts: 3,
            baseDelayMs: 100,
            maxDelayMs: 5000,
            multiplier: 2.0,
        );

        self::assertSame(RetryDecision::Retry, $policy->shouldRetry(1));
        self::assertSame(RetryDecision::Retry, $policy->shouldRetry(2));
    }

    #[Test]
    public function retryPolicyReturnsDeadLetterWhenMaxAttemptsReached(): void
    {
        $policy = new QueueRetryPolicy(
            maxAttempts: 3,
            baseDelayMs: 100,
            maxDelayMs: 5000,
            multiplier: 2.0,
        );

        self::assertSame(RetryDecision::DeadLetter, $policy->shouldRetry(3));
        self::assertSame(RetryDecision::DeadLetter, $policy->shouldRetry(4));
    }

    #[Test]
    public function retryPolicyCalculatesExponentialBackoffDelay(): void
    {
        $policy = new QueueRetryPolicy(
            maxAttempts: 5,
            baseDelayMs: 100,
            maxDelayMs: 5000,
            multiplier: 2.0,
        );

        // delay = baseDelayMs * multiplier^(attempt-1)
        self::assertSame(100, $policy->getDelay(1));  // 100 * 2^0 = 100
        self::assertSame(200, $policy->getDelay(2));  // 100 * 2^1 = 200
        self::assertSame(400, $policy->getDelay(3));  // 100 * 2^2 = 400
        self::assertSame(800, $policy->getDelay(4));  // 100 * 2^3 = 800
        self::assertSame(1600, $policy->getDelay(5)); // 100 * 2^4 = 1600
    }

    #[Test]
    public function retryPolicyDelayIsCappedAtMaxDelay(): void
    {
        $policy = new QueueRetryPolicy(
            maxAttempts: 10,
            baseDelayMs: 1000,
            maxDelayMs: 5000,
            multiplier: 3.0,
        );

        // attempt 4: 1000 * 3^3 = 27000, capped at 5000
        self::assertSame(5000, $policy->getDelay(4));
    }

    #[Test]
    public function retryPolicyCanBeBuiltFromConfig(): void
    {
        $config = new QueueConfig(
            retryMaxAttempts: 5,
            retryBaseDelayMs: 500,
            retryMaxDelayMs: 30000,
            retryMultiplier: 1.5,
        );

        $policy = QueueRetryPolicy::fromConfig($config);

        self::assertSame(RetryDecision::Retry, $policy->shouldRetry(1));
        self::assertSame(RetryDecision::Retry, $policy->shouldRetry(4));
        self::assertSame(RetryDecision::DeadLetter, $policy->shouldRetry(5));
        self::assertSame(500, $policy->getDelay(1));
    }

    // ---- Max retries -> dead letter queue ----

    #[Test]
    public function failedJobIsMovedToDeadLetterQueueAfterMaxRetries(): void
    {
        $driver = new InMemoryDriver();
        $dlq = new DeadLetterQueue($driver);
        $policy = new QueueRetryPolicy(
            maxAttempts: 2,
            baseDelayMs: 100,
            maxDelayMs: 5000,
            multiplier: 2.0,
        );

        // Push a failing job as a serialized envelope
        $this->pushEnvelope($driver, 'default', PipelineFailingJob::class, '{"reason":"test"}');

        // Pop and "process" the job (simulating worker behavior)
        $record = $driver->pop('default');
        self::assertNotNull($record);

        // First attempt fails
        $decision = $policy->shouldRetry($record->attempts);
        self::assertSame(RetryDecision::Retry, $decision);

        // Reject it (marks as failed in driver)
        $driver->reject($record->id, 'Test failure');

        // Simulate re-push for retry by creating a new record with an envelope
        $this->pushEnvelope($driver, 'default', PipelineFailingJob::class, '{"reason":"test"}');
        $retryRecord = $driver->pop('default');
        self::assertNotNull($retryRecord);

        // Second attempt: now at max attempts
        $decision2 = $policy->shouldRetry($retryRecord->attempts + 1); // +1 because first attempt already happened
        self::assertSame(RetryDecision::DeadLetter, $decision2);

        // Store in DLQ
        $dlq->store($retryRecord, 'Test failure after max retries');

        $listed = $dlq->list();
        self::assertCount(1, $listed);
        self::assertSame('default', $listed[0]->queue);
        self::assertSame(PipelineFailingJob::class, $listed[0]->jobClass);
        self::assertSame('Test failure after max retries', $listed[0]->exception);
    }

    #[Test]
    public function deadLetterQueueRetryRedispatchesJobToOriginalQueue(): void
    {
        $driver = new InMemoryDriver();
        $dlq = new DeadLetterQueue($driver);

        // Simulate a failed job stored in DLQ
        $this->pushEnvelope($driver, 'emails', PipelineSuccessJob::class, '{"retry":"true"}');
        $record = $driver->pop('emails');
        self::assertNotNull($record);
        $driver->reject($record->id, 'Temporary failure');

        // Store in DLQ
        $dlq->store($record, 'Temporary failure');

        // Retry from DLQ
        $dlq->retry($record->id);

        // The job should be re-dispatched to the 'emails' queue
        self::assertCount(0, $dlq->list());
        // The re-pushed job is now pending (plus the original failed one still in driver)
        $pending = $driver->findByStatus(JobRecordStatus::Pending);
        self::assertCount(1, $pending);
        self::assertSame('emails', $pending[0]->queue);
    }

    #[Test]
    public function deadLetterQueuePurgeRemovesAllFailedJobs(): void
    {
        $driver = new InMemoryDriver();
        $dlq = new DeadLetterQueue($driver);

        // Store multiple failed jobs
        for ($i = 0; $i < 3; $i++) {
            $this->pushEnvelope($driver, 'default', PipelineFailingJob::class, '{}');
            $record = $driver->pop('default');
            self::assertNotNull($record);
            $driver->reject($record->id, "Failure {$i}");
            $dlq->store($record, "Failure {$i}");
        }

        self::assertCount(3, $dlq->list());

        $purged = $dlq->purge();
        self::assertSame(3, $purged);
        self::assertCount(0, $dlq->list());
    }

    // ---- Worker stop conditions ----

    #[Test]
    public function workerStopsAfterMaxJobsReached(): void
    {
        PipelineCountingJob::$count = 0;

        $driver = new InMemoryDriver();
        for ($i = 0; $i < 5; $i++) {
            $this->pushEnvelope($driver, 'default', PipelineCountingJob::class, '{}');
        }

        $options = new WorkerOptions(maxJobs: 3, sleepMs: 1, maxMemoryMb: 0);
        $worker = $this->createWorker($driver, $options);
        $worker->run('default');

        /** @var int $processedCount */
        $processedCount = PipelineCountingJob::$count;
        self::assertSame(3, $processedCount);
        self::assertSame(WorkerStatus::Stopped, $worker->status);
    }

    #[Test]
    public function workerStopsWhenQueueIsEmptyAfterProcessingAllJobs(): void
    {
        PipelineCountingJob::$count = 0;

        $driver = new InMemoryDriver();
        $this->pushEnvelope($driver, 'default', PipelineCountingJob::class, '{}');
        $this->pushEnvelope($driver, 'default', PipelineCountingJob::class, '{}');

        // maxJobs is higher than available jobs; worker will run idle loop once then stop due to time/signal
        // Use maxJobs to cap at 10 so test doesn't hang
        $options = new WorkerOptions(maxJobs: 10, sleepMs: 1, timeLimitSeconds: 1, maxMemoryMb: 0);
        $worker = $this->createWorker($driver, $options);
        $worker->run('default');

        /** @var int $processedCount */
        $processedCount = PipelineCountingJob::$count;
        self::assertSame(2, $processedCount);
        self::assertSame(WorkerStatus::Stopped, $worker->status);
    }

    #[Test]
    public function workerStopsOnManualStopCall(): void
    {
        $driver = new InMemoryDriver();
        $options = new WorkerOptions(sleepMs: 1);
        $worker = $this->createWorker($driver, $options);

        $worker->stop();

        self::assertSame(WorkerStatus::Stopping, $worker->status);
    }

    #[Test]
    public function workerProcessNextJobReturnsFalseWhenQueueEmpty(): void
    {
        $driver = new InMemoryDriver();
        $options = new WorkerOptions();
        $worker = $this->createWorker($driver, $options);

        $result = $worker->processNextJob('default');

        self::assertFalse($result);
    }

    #[Test]
    public function workerProcessNextJobReturnsTrueWhenJobProcessed(): void
    {
        PipelineSuccessJob::$handled = false;

        $driver = new InMemoryDriver();
        $this->pushEnvelope($driver, 'default', PipelineSuccessJob::class, '{}');

        $options = new WorkerOptions();
        $worker = $this->createWorker($driver, $options);

        $result = $worker->processNextJob('default');

        self::assertTrue($result);
        self::assertTrue(PipelineSuccessJob::$handled);
    }

    #[Test]
    public function queueManagerDispatchesToSpecificQueue(): void
    {
        $driver = new InMemoryDriver();
        $config = new QueueConfig(
            enabled: true,
            driver: QueueDriverType::Memory,
            defaultQueue: 'default',
        );

        $manager = new QueueManager($config, $driver);
        $manager->dispatch(PipelineSuccessJob::class, '{}', 'high-priority');

        self::assertSame(0, $driver->size('default'));
        self::assertSame(1, $driver->size('high-priority'));
    }

    #[Test]
    public function queueManagerUsesDefaultQueueWhenNoneSpecified(): void
    {
        $driver = new InMemoryDriver();
        $config = new QueueConfig(
            enabled: true,
            driver: QueueDriverType::Memory,
            defaultQueue: 'my-queue',
        );

        $manager = new QueueManager($config, $driver);
        $manager->dispatch(PipelineSuccessJob::class, '{}');

        self::assertSame(1, $driver->size('my-queue'));
        self::assertSame(0, $driver->size('default'));
    }

    #[Test]
    public function endToEndDispatchProcessAndVerify(): void
    {
        PipelineSuccessJob::$handled = false;
        PipelineSuccessJob::$capturedContext = null;

        $driver = new InMemoryDriver();
        $config = new QueueConfig(
            enabled: true,
            driver: QueueDriverType::Memory,
            defaultQueue: 'work',
        );

        $manager = new QueueManager($config, $driver);

        // Dispatch
        $jobId = $manager->dispatch(PipelineSuccessJob::class, '{"task":"complete"}');
        self::assertNotEmpty($jobId);
        self::assertSame(1, $manager->size());

        // Process
        $options = new WorkerOptions(maxJobs: 1, sleepMs: 1, maxMemoryMb: 0);
        $worker = $this->createWorker($driver, $options);
        $worker->run('work');

        // Verify
        self::assertTrue(PipelineSuccessJob::$handled);
        self::assertSame(0, $manager->size());
        self::assertCount(0, $driver->getAll());
        self::assertSame(WorkerStatus::Stopped, $worker->status);
    }

    protected function tearDown(): void
    {
        PipelineSuccessJob::$handled = false;
        PipelineSuccessJob::$capturedContext = null;
        PipelineFailingJob::$handleCount = 0;
        PipelineCountingJob::$count = 0;
        PipelineOrderTracker::$order = [];
    }

    /**
     * Push a properly serialized job envelope to the driver.
     *
     * The Worker expects job record payloads to be serialized envelopes.
     * This helper builds and serializes an envelope so that tests pushing
     * directly to the driver (bypassing QueueManager) provide valid data.
     */
    private function pushEnvelope(
        InMemoryDriver $driver,
        string $queue,
        string $jobClass,
        string $payload,
        int $attempt = 1,
        int $retryMaxAttempts = 3,
    ): string {
        $envelope = new JobEnvelope(
            id: bin2hex(random_bytes(16)),
            jobClass: $jobClass,
            payload: $payload,
            queue: $queue,
            idempotencyKey: '',
            correlationId: '',
            traceId: null,
            spanId: null,
            schemaVersion: 1,
            keyId: null,
            retryMaxAttempts: $retryMaxAttempts,
            retryBackoffStrategy: BackoffStrategy::Exponential,
            retryDelayMs: 1000,
            tenantId: null,
            subjectId: null,
            batchId: null,
            chainIndex: null,
            attempt: $attempt,
            dispatchedAt: time(),
            encrypted: false,
        );

        $serialized = $this->envelopeSerializer->serialize($envelope);

        return $driver->push($queue, $jobClass, $serialized);
    }
}

/**
 * @internal Test double -- successful job that records execution
 */
final class PipelineSuccessJob implements QueueableInterface
{
    public static bool $handled = false;
    public static ?JobContext $capturedContext = null;

    public function handle(JobContext $context): void
    {
        self::$handled = true;
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

/**
 * @internal Test double -- always throws
 */
final class PipelineFailingJob implements QueueableInterface
{
    public static int $handleCount = 0;

    public function handle(JobContext $context): void
    {
        self::$handleCount++;
        throw new RuntimeException('Intentional test failure');
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
 * @internal Test double -- counts invocations
 */
final class PipelineCountingJob implements QueueableInterface
{
    public static int $count = 0;

    public function handle(JobContext $context): void
    {
        self::$count++;
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
 * @internal Shared tracker for order-tracking jobs
 */
final class PipelineOrderTracker
{
    /** @var list<string> */
    public static array $order = [];
}

/**
 * @internal Test double -- order tracker: first
 */
final class PipelineOrderTrackerFirst implements QueueableInterface
{
    public function handle(JobContext $context): void
    {
        PipelineOrderTracker::$order[] = 'first';
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
 * @internal Test double -- order tracker: second
 */
final class PipelineOrderTrackerSecond implements QueueableInterface
{
    public function handle(JobContext $context): void
    {
        PipelineOrderTracker::$order[] = 'second';
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
 * @internal Test double -- order tracker: third
 */
final class PipelineOrderTrackerThird implements QueueableInterface
{
    public function handle(JobContext $context): void
    {
        PipelineOrderTracker::$order[] = 'third';
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
