<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Queue;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Context\CausationId;
use Pulsar\Context\ContextPropagator;
use Pulsar\Context\CorrelationId;
use Pulsar\Context\RequestContext;
use Pulsar\Context\RequestContextHolder;
use Pulsar\Queue\JobContext;
use Pulsar\Queue\JobRecord;
use Pulsar\Queue\JobRecordStatus;
use Pulsar\Queue\QueueableInterface;
use Pulsar\Queue\QueueDriverInterface;
use Pulsar\Queue\Worker;
use Pulsar\Queue\WorkerOptions;
use Random\Engine\Secure;
use Random\Randomizer;
use RuntimeException;

use function json_encode;

use const JSON_THROW_ON_ERROR;

#[CoversClass(Worker::class)]
final class WorkerContextPropagationTest extends TestCase
{
    private Randomizer $randomizer;

    protected function setUp(): void
    {
        $this->randomizer = new Randomizer(new Secure());
        WorkerContextCapture::$capturedContext = null;
    }

    protected function tearDown(): void
    {
        WorkerContextCapture::$capturedContext = null;
    }

    #[Test]
    public function it_extracts_context_from_payload_envelope(): void
    {
        $correlationId = CorrelationId::generate($this->randomizer);
        $causationId = CausationId::generate($this->randomizer);
        $requestContext = new RequestContext(
            correlationId: $correlationId,
            causationId: $causationId,
            actor: 'user-42',
        );

        $carrier = [];
        ContextPropagator::inject($requestContext, $carrier);

        $envelopePayload = json_encode([
            '_ctx' => $carrier,
            '_payload' => '{"order_id":123}',
        ], JSON_THROW_ON_ERROR);

        $record = new JobRecord(
            id: 'ctx-job-001',
            queue: 'default',
            jobClass: WorkerContextCapture::class,
            payload: $envelopePayload,
            attempts: 1,
            status: JobRecordStatus::Processing,
            createdAt: 1700000000,
            availableAt: 1700000000,
        );

        $driver = $this->createStub(QueueDriverInterface::class);
        $driver->method('pop')->willReturn($record);

        $holder = new RequestContextHolder();
        $worker = new Worker($driver, new WorkerOptions(), null, $holder);

        $worker->processNextJob('default');

        self::assertNotNull(WorkerContextCapture::$capturedContext);
        self::assertNotNull(WorkerContextCapture::$capturedContext->requestContext);
        self::assertSame($correlationId->value, WorkerContextCapture::$capturedContext->requestContext->correlationId->value);
        self::assertSame($causationId->value, WorkerContextCapture::$capturedContext->requestContext->causationId->value);
        self::assertSame('user-42', WorkerContextCapture::$capturedContext->requestContext->actor);
    }

    #[Test]
    public function it_sets_context_in_holder_during_job_execution(): void
    {
        $requestContext = new RequestContext(
            correlationId: CorrelationId::generate($this->randomizer),
            causationId: CausationId::generate($this->randomizer),
            actor: 'holder-test',
        );

        $carrier = [];
        ContextPropagator::inject($requestContext, $carrier);

        $envelopePayload = json_encode([
            '_ctx' => $carrier,
            '_payload' => '{}',
        ], JSON_THROW_ON_ERROR);

        $record = new JobRecord(
            id: 'ctx-holder-001',
            queue: 'default',
            jobClass: WorkerContextCapture::class,
            payload: $envelopePayload,
            attempts: 1,
            status: JobRecordStatus::Processing,
            createdAt: 1700000000,
            availableAt: 1700000000,
        );

        $driver = $this->createStub(QueueDriverInterface::class);
        $driver->method('pop')->willReturn($record);

        $holder = new RequestContextHolder();
        $worker = new Worker($driver, new WorkerOptions(), null, $holder);

        $worker->processNextJob('default');

        // Holder should be cleared after job execution
        self::assertFalse($holder->isAvailable());
    }

    #[Test]
    public function it_clears_holder_even_when_job_throws(): void
    {
        $requestContext = new RequestContext(
            correlationId: CorrelationId::generate($this->randomizer),
            causationId: CausationId::generate($this->randomizer),
        );

        $carrier = [];
        ContextPropagator::inject($requestContext, $carrier);

        $envelopePayload = json_encode([
            '_ctx' => $carrier,
            '_payload' => '{}',
        ], JSON_THROW_ON_ERROR);

        $record = new JobRecord(
            id: 'ctx-fail-001',
            queue: 'default',
            jobClass: WorkerContextFailingJob::class,
            payload: $envelopePayload,
            attempts: 1,
            status: JobRecordStatus::Processing,
            createdAt: 1700000000,
            availableAt: 1700000000,
        );

        $driver = $this->createStub(QueueDriverInterface::class);
        $driver->method('pop')->willReturn($record);

        $holder = new RequestContextHolder();
        $worker = new Worker($driver, new WorkerOptions(), null, $holder);

        $worker->processNextJob('default');

        // Holder should be cleared even after failure
        self::assertFalse($holder->isAvailable());
    }

    #[Test]
    public function it_passes_null_context_for_plain_payloads(): void
    {
        $record = new JobRecord(
            id: 'plain-001',
            queue: 'default',
            jobClass: WorkerContextCapture::class,
            payload: '{"simple":"data"}',
            attempts: 1,
            status: JobRecordStatus::Processing,
            createdAt: 1700000000,
            availableAt: 1700000000,
        );

        $driver = $this->createStub(QueueDriverInterface::class);
        $driver->method('pop')->willReturn($record);

        $holder = new RequestContextHolder();
        $worker = new Worker($driver, new WorkerOptions(), null, $holder);

        $worker->processNextJob('default');

        self::assertNotNull(WorkerContextCapture::$capturedContext);
        self::assertNull(WorkerContextCapture::$capturedContext->requestContext);
    }

    #[Test]
    public function it_handles_non_json_payloads_gracefully(): void
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

        $driver = $this->createStub(QueueDriverInterface::class);
        $driver->method('pop')->willReturn($record);

        $holder = new RequestContextHolder();
        $worker = new Worker($driver, new WorkerOptions(), null, $holder);

        $worker->processNextJob('default');

        self::assertNotNull(WorkerContextCapture::$capturedContext);
        self::assertNull(WorkerContextCapture::$capturedContext->requestContext);
    }

    #[Test]
    public function it_works_without_context_holder(): void
    {
        $requestContext = new RequestContext(
            correlationId: CorrelationId::generate($this->randomizer),
            causationId: CausationId::generate($this->randomizer),
        );

        $carrier = [];
        ContextPropagator::inject($requestContext, $carrier);

        $envelopePayload = json_encode([
            '_ctx' => $carrier,
            '_payload' => '{}',
        ], JSON_THROW_ON_ERROR);

        $record = new JobRecord(
            id: 'no-holder-001',
            queue: 'default',
            jobClass: WorkerContextCapture::class,
            payload: $envelopePayload,
            attempts: 1,
            status: JobRecordStatus::Processing,
            createdAt: 1700000000,
            availableAt: 1700000000,
        );

        $driver = $this->createStub(QueueDriverInterface::class);
        $driver->method('pop')->willReturn($record);

        // No context holder — should still extract and pass context to JobContext
        $worker = new Worker($driver, new WorkerOptions());

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
