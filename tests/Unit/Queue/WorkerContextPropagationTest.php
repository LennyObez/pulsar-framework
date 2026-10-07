<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Queue;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Context\RequestContextHolder;
use Pulsar\Queue\Envelope\BackoffStrategy;
use Pulsar\Queue\Envelope\EnvelopeSerializer;
use Pulsar\Queue\Envelope\JobEnvelope;
use Pulsar\Queue\JobContext;
use Pulsar\Queue\JobRecord;
use Pulsar\Queue\JobRecordStatus;
use Pulsar\Queue\QueueableInterface;
use Pulsar\Queue\QueueDriverInterface;
use Pulsar\Queue\Serialization\TypeRegistry;
use Pulsar\Queue\Worker;
use Pulsar\Queue\WorkerOptions;
use Random\Engine\Secure;
use Random\Randomizer;
use RuntimeException;

use function bin2hex;

#[CoversClass(Worker::class)]
final class WorkerContextPropagationTest extends TestCase
{
    private Randomizer $randomizer;

    private EnvelopeSerializer $serializer;

    private TypeRegistry $typeRegistry;

    protected function setUp(): void
    {
        $this->randomizer = new Randomizer(new Secure());
        $this->serializer = new EnvelopeSerializer();
        $this->typeRegistry = new TypeRegistry();
        $this->typeRegistry->register(WorkerContextCapture::class);
        $this->typeRegistry->register(WorkerContextFailingJob::class);
        WorkerContextCapture::$capturedContext = null;
    }

    protected function tearDown(): void
    {
        WorkerContextCapture::$capturedContext = null;
    }

    #[Test]
    public function it_extracts_context_from_payload_envelope(): void
    {
        $envelopeId = bin2hex($this->randomizer->getBytes(16));
        $correlationId = bin2hex($this->randomizer->getBytes(16));

        $envelope = new JobEnvelope(
            id: $envelopeId,
            jobClass: WorkerContextCapture::class,
            payload: '{"order_id":123}',
            queue: 'default',
            idempotencyKey: 'idem-001',
            correlationId: $correlationId,
            traceId: null,
            spanId: null,
            schemaVersion: 1,
            keyId: null,
            retryMaxAttempts: 3,
            retryBackoffStrategy: BackoffStrategy::Exponential,
            retryDelayMs: 1000,
            tenantId: null,
            subjectId: 'user-42',
            batchId: null,
            chainIndex: null,
            attempt: 1,
            dispatchedAt: 1700000000,
            encrypted: false,
        );

        $record = new JobRecord(
            id: 'ctx-job-001',
            queue: 'default',
            jobClass: WorkerContextCapture::class,
            payload: $this->serializer->serialize($envelope),
            attempts: 1,
            status: JobRecordStatus::Processing,
            createdAt: 1700000000,
            availableAt: 1700000000,
        );

        $driver = $this->createStub(QueueDriverInterface::class);
        $driver->method('pop')->willReturn($record);

        $holder = new RequestContextHolder();
        $worker = new Worker($driver, new WorkerOptions(), null, $holder, typeRegistry: $this->typeRegistry);

        $worker->processNextJob('default');

        self::assertNotNull(WorkerContextCapture::$capturedContext);
        self::assertNotNull(WorkerContextCapture::$capturedContext->requestContext);
        self::assertSame($correlationId, WorkerContextCapture::$capturedContext->requestContext->correlationId->value);
        // Causation ID is set to the envelope's id
        self::assertSame($envelopeId, WorkerContextCapture::$capturedContext->requestContext->causationId->value);
        self::assertSame('user-42', WorkerContextCapture::$capturedContext->requestContext->actor);
    }

    #[Test]
    public function it_sets_context_in_holder_during_job_execution(): void
    {
        $envelopeId = bin2hex($this->randomizer->getBytes(16));
        $correlationId = bin2hex($this->randomizer->getBytes(16));

        $envelope = new JobEnvelope(
            id: $envelopeId,
            jobClass: WorkerContextCapture::class,
            payload: '{}',
            queue: 'default',
            idempotencyKey: 'idem-holder-001',
            correlationId: $correlationId,
            traceId: null,
            spanId: null,
            schemaVersion: 1,
            keyId: null,
            retryMaxAttempts: 3,
            retryBackoffStrategy: BackoffStrategy::Exponential,
            retryDelayMs: 1000,
            tenantId: 'tenant-abc',
            subjectId: 'holder-test',
            batchId: null,
            chainIndex: null,
            attempt: 1,
            dispatchedAt: 1700000000,
            encrypted: false,
        );

        $record = new JobRecord(
            id: 'ctx-holder-001',
            queue: 'default',
            jobClass: WorkerContextCapture::class,
            payload: $this->serializer->serialize($envelope),
            attempts: 1,
            status: JobRecordStatus::Processing,
            createdAt: 1700000000,
            availableAt: 1700000000,
        );

        $driver = $this->createStub(QueueDriverInterface::class);
        $driver->method('pop')->willReturn($record);

        $holder = new RequestContextHolder();
        $worker = new Worker($driver, new WorkerOptions(), null, $holder, typeRegistry: $this->typeRegistry);

        $worker->processNextJob('default');

        // Holder should be cleared after job execution
        self::assertFalse($holder->isAvailable());
    }

    #[Test]
    public function it_clears_holder_even_when_job_throws(): void
    {
        $envelopeId = bin2hex($this->randomizer->getBytes(16));
        $correlationId = bin2hex($this->randomizer->getBytes(16));

        $envelope = new JobEnvelope(
            id: $envelopeId,
            jobClass: WorkerContextFailingJob::class,
            payload: '{}',
            queue: 'default',
            idempotencyKey: 'idem-fail-001',
            correlationId: $correlationId,
            traceId: null,
            spanId: null,
            schemaVersion: 1,
            keyId: null,
            retryMaxAttempts: 3,
            retryBackoffStrategy: BackoffStrategy::Exponential,
            retryDelayMs: 1000,
            tenantId: null,
            subjectId: null,
            batchId: null,
            chainIndex: null,
            attempt: 1,
            dispatchedAt: 1700000000,
            encrypted: false,
        );

        $record = new JobRecord(
            id: 'ctx-fail-001',
            queue: 'default',
            jobClass: WorkerContextFailingJob::class,
            payload: $this->serializer->serialize($envelope),
            attempts: 1,
            status: JobRecordStatus::Processing,
            createdAt: 1700000000,
            availableAt: 1700000000,
        );

        $driver = $this->createStub(QueueDriverInterface::class);
        $driver->method('pop')->willReturn($record);

        $holder = new RequestContextHolder();
        $worker = new Worker($driver, new WorkerOptions(), null, $holder, typeRegistry: $this->typeRegistry);

        $worker->processNextJob('default');

        // Holder should be cleared even after failure
        self::assertFalse($holder->isAvailable());
    }

    #[Test]
    public function it_passes_null_context_for_envelope_without_correlation_id(): void
    {
        $envelopeId = bin2hex($this->randomizer->getBytes(16));

        $envelope = new JobEnvelope(
            id: $envelopeId,
            jobClass: WorkerContextCapture::class,
            payload: '{"simple":"data"}',
            queue: 'default',
            idempotencyKey: 'idem-plain-001',
            correlationId: '',
            traceId: null,
            spanId: null,
            schemaVersion: 1,
            keyId: null,
            retryMaxAttempts: 3,
            retryBackoffStrategy: BackoffStrategy::Exponential,
            retryDelayMs: 1000,
            tenantId: null,
            subjectId: null,
            batchId: null,
            chainIndex: null,
            attempt: 1,
            dispatchedAt: 1700000000,
            encrypted: false,
        );

        $record = new JobRecord(
            id: 'plain-001',
            queue: 'default',
            jobClass: WorkerContextCapture::class,
            payload: $this->serializer->serialize($envelope),
            attempts: 1,
            status: JobRecordStatus::Processing,
            createdAt: 1700000000,
            availableAt: 1700000000,
        );

        $driver = $this->createStub(QueueDriverInterface::class);
        $driver->method('pop')->willReturn($record);

        $holder = new RequestContextHolder();
        $worker = new Worker($driver, new WorkerOptions(), null, $holder, typeRegistry: $this->typeRegistry);

        $worker->processNextJob('default');

        self::assertNotNull(WorkerContextCapture::$capturedContext);
        self::assertNull(WorkerContextCapture::$capturedContext->requestContext);
    }

    #[Test]
    public function it_rejects_job_with_invalid_envelope_payload(): void
    {
        $record = new JobRecord(
            id: 'raw-001',
            queue: 'default',
            jobClass: WorkerContextCapture::class,
            payload: 'not-json-at-all',
            attempts: 1,
            status: JobRecordStatus::Processing,
            createdAt: 1700000000,
            availableAt: 1700000000,
        );

        $driver = $this->createMock(QueueDriverInterface::class);
        $driver->method('pop')->willReturn($record);
        $driver->expects(self::once())
            ->method('reject')
            ->with('raw-001', self::stringContains('Envelope deserialization failed'));

        $holder = new RequestContextHolder();
        $worker = new Worker($driver, new WorkerOptions(), null, $holder, typeRegistry: $this->typeRegistry);

        $result = $worker->processNextJob('default');

        // Job was popped and processed (returned true) but rejected due to bad envelope
        self::assertTrue($result);
        // The job handler was never invoked
        self::assertNull(WorkerContextCapture::$capturedContext);
    }

    #[Test]
    public function it_works_without_context_holder(): void
    {
        $envelopeId = bin2hex($this->randomizer->getBytes(16));
        $correlationId = bin2hex($this->randomizer->getBytes(16));

        $envelope = new JobEnvelope(
            id: $envelopeId,
            jobClass: WorkerContextCapture::class,
            payload: '{}',
            queue: 'default',
            idempotencyKey: 'idem-no-holder-001',
            correlationId: $correlationId,
            traceId: null,
            spanId: null,
            schemaVersion: 1,
            keyId: null,
            retryMaxAttempts: 3,
            retryBackoffStrategy: BackoffStrategy::Exponential,
            retryDelayMs: 1000,
            tenantId: null,
            subjectId: null,
            batchId: null,
            chainIndex: null,
            attempt: 1,
            dispatchedAt: 1700000000,
            encrypted: false,
        );

        $record = new JobRecord(
            id: 'no-holder-001',
            queue: 'default',
            jobClass: WorkerContextCapture::class,
            payload: $this->serializer->serialize($envelope),
            attempts: 1,
            status: JobRecordStatus::Processing,
            createdAt: 1700000000,
            availableAt: 1700000000,
        );

        $driver = $this->createStub(QueueDriverInterface::class);
        $driver->method('pop')->willReturn($record);

        // No context holder — should still extract and pass context to JobContext
        $worker = new Worker($driver, new WorkerOptions(), typeRegistry: $this->typeRegistry);

        $worker->processNextJob('default');

        self::assertNotNull(WorkerContextCapture::$capturedContext);
        self::assertNotNull(WorkerContextCapture::$capturedContext->requestContext);
    }
}

/**
 * @internal Test double — captures JobContext
 */
final class WorkerContextCapture implements QueueableInterface
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

/**
 * @internal Test double — throws during execution
 */
final class WorkerContextFailingJob implements QueueableInterface
{
    public function handle(JobContext $context): void
    {
        throw new RuntimeException('Intentional failure in context test');
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
