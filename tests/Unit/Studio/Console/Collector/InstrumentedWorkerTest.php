<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Console\Collector;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Collector\InstrumentedWorker;
use Pulsar\Extension\Studio\Console\Event\ConsoleEvent;
use Pulsar\Extension\Studio\Console\Event\Payload\JobPayload;
use Pulsar\Extension\Studio\FiberScopedContextProvider;
use Pulsar\Observability\Context\CorrelationContext;
use Pulsar\Queue\JobContext;
use Pulsar\Queue\JobRecord;
use Pulsar\Queue\JobRecordStatus;
use Pulsar\Queue\QueueableInterface;
use Pulsar\Queue\QueueDriverInterface;
use Pulsar\Queue\Worker;
use Pulsar\Queue\WorkerOptions;
use Pulsar\Queue\WorkerStatus;
use RuntimeException;

#[CoversClass(InstrumentedWorker::class)]
final class InstrumentedWorkerTest extends TestCase
{
    private QueueDriverInterface&\PHPUnit\Framework\MockObject\Stub $driver;
    private Worker $inner;
    private FiberScopedContextProvider $contextProvider;

    /** @var list<array{event: ConsoleEvent, context: ?CorrelationContext}> */
    private array $emittedEvents;

    protected function setUp(): void
    {
        $this->driver = $this->createStub(QueueDriverInterface::class);
        $options = new WorkerOptions();
        $this->inner = new Worker($this->driver, $options);
        $this->contextProvider = new FiberScopedContextProvider();
        $this->emittedEvents = [];
    }

    private function createInstrumented(): InstrumentedWorker
    {
        return new InstrumentedWorker(
            $this->inner,
            $this->contextProvider,
            function (ConsoleEvent $event, ?CorrelationContext $context): void {
                $this->emittedEvents[] = ['event' => $event, 'context' => $context];
            },
        );
    }

    #[Test]
    public function it_returns_false_when_no_job_available(): void
    {
        $this->driver->method('pop')->willReturn(null);

        $worker = $this->createInstrumented();
        $result = $worker->processNextJob('default');

        self::assertFalse($result);
        self::assertCount(0, $this->emittedEvents);
    }

    #[Test]
    public function it_emits_completed_event_on_successful_job(): void
    {
        $record = new JobRecord(
            id: 'instr-001',
            queue: 'default',
            jobClass: InstrumentedWorkerSuccessJob::class,
            payload: '{}',
            attempts: 1,
            status: JobRecordStatus::Processing,
            createdAt: 1700000000,
            availableAt: 1700000000,
        );

        $this->driver->method('pop')->willReturn($record);
        $this->driver->method('acknowledge');

        $worker = $this->createInstrumented();
        $result = $worker->processNextJob('default');

        self::assertTrue($result);
        self::assertCount(1, $this->emittedEvents);

        /** @var JobPayload $event */
        $event = $this->emittedEvents[0]['event'];
        self::assertInstanceOf(JobPayload::class, $event);
        self::assertSame('completed', $event->status);
        self::assertSame('default', $event->queue);
        self::assertNull($event->errorMessage);
        self::assertNotNull($event->durationMs);
        self::assertGreaterThanOrEqual(0.0, $event->durationMs);
    }

    #[Test]
    public function it_does_not_emit_when_disabled_and_delegates_to_inner(): void
    {
        $record = new JobRecord(
            id: 'instr-disabled',
            queue: 'default',
            jobClass: InstrumentedWorkerSuccessJob::class,
            payload: '{}',
            attempts: 1,
            status: JobRecordStatus::Processing,
            createdAt: 1700000000,
            availableAt: 1700000000,
        );

        $this->driver->method('pop')->willReturn($record);
        $this->driver->method('acknowledge');

        $worker = $this->createInstrumented();
        $worker->enabled = false;

        $result = $worker->processNextJob('default');

        self::assertTrue($result);
        self::assertCount(0, $this->emittedEvents);
    }

    #[Test]
    public function it_delegates_stop_to_inner(): void
    {
        $worker = $this->createInstrumented();

        $worker->stop();

        self::assertSame(WorkerStatus::Stopping, $this->inner->status);
    }

    #[Test]
    public function it_exposes_inner_worker_status(): void
    {
        $worker = $this->createInstrumented();

        self::assertSame(WorkerStatus::Stopped, $worker->status());
    }

    #[Test]
    public function it_exposes_inner_worker(): void
    {
        $worker = $this->createInstrumented();

        self::assertSame($this->inner, $worker->inner());
    }

    #[Test]
    public function it_provides_correlation_context_with_job_id(): void
    {
        $record = new JobRecord(
            id: 'instr-ctx',
            queue: 'default',
            jobClass: InstrumentedWorkerSuccessJob::class,
            payload: '{}',
            attempts: 1,
            status: JobRecordStatus::Processing,
            createdAt: 1700000000,
            availableAt: 1700000000,
        );

        $this->driver->method('pop')->willReturn($record);
        $this->driver->method('acknowledge');

        $worker = $this->createInstrumented();
        $worker->processNextJob('default');

        self::assertCount(1, $this->emittedEvents);

        /** @var CorrelationContext $context */
        $context = $this->emittedEvents[0]['context'];
        self::assertInstanceOf(CorrelationContext::class, $context);
        self::assertNotNull($context->jobId);
        self::assertNotEmpty($context->jobId);
    }

    #[Test]
    public function it_preserves_existing_correlation_context_fields(): void
    {
        $existing = new CorrelationContext(
            requestId: 'req-123',
            traceId: 'trace-456',
            spanId: 'span-789',
        );
        $scope = $this->contextProvider->enter($existing);

        $record = new JobRecord(
            id: 'instr-preserve',
            queue: 'default',
            jobClass: InstrumentedWorkerSuccessJob::class,
            payload: '{}',
            attempts: 1,
            status: JobRecordStatus::Processing,
            createdAt: 1700000000,
            availableAt: 1700000000,
        );

        $this->driver->method('pop')->willReturn($record);
        $this->driver->method('acknowledge');

        try {
            $worker = $this->createInstrumented();
            $worker->processNextJob('default');

            self::assertCount(1, $this->emittedEvents);

            /** @var CorrelationContext $context */
            $context = $this->emittedEvents[0]['context'];
            self::assertSame('req-123', $context->requestId);
            self::assertSame('trace-456', $context->traceId);
            self::assertSame('span-789', $context->spanId);
            self::assertNotNull($context->jobId);
        } finally {
            $scope->close();
        }
    }

    #[Test]
    public function it_swallows_emit_exceptions(): void
    {
        $record = new JobRecord(
            id: 'instr-swallow',
            queue: 'default',
            jobClass: InstrumentedWorkerSuccessJob::class,
            payload: '{}',
            attempts: 1,
            status: JobRecordStatus::Processing,
            createdAt: 1700000000,
            availableAt: 1700000000,
        );

        $this->driver->method('pop')->willReturn($record);
        $this->driver->method('acknowledge');

        $worker = new InstrumentedWorker(
            $this->inner,
            $this->contextProvider,
            function (ConsoleEvent $event, ?CorrelationContext $context): void {
                throw new RuntimeException('Emit error');
            },
        );

        // Should not throw
        $result = $worker->processNextJob('default');

        self::assertTrue($result);
    }

    #[Test]
    public function it_starts_enabled_by_default(): void
    {
        $worker = $this->createInstrumented();

        self::assertTrue($worker->enabled);
    }

    #[Test]
    public function it_delegates_run_to_inner(): void
    {
        $record = new JobRecord(
            id: 'run-delegate',
            queue: 'default',
            jobClass: InstrumentedWorkerSuccessJob::class,
            payload: '{}',
            attempts: 1,
            status: JobRecordStatus::Processing,
            createdAt: 1700000000,
            availableAt: 1700000000,
        );

        $this->driver->method('pop')->willReturn($record);
        $this->driver->method('acknowledge');

        // maxJobs: 1 so the worker processes one job then recycles
        $options = new WorkerOptions(maxJobs: 1, sleepMs: 1);
        $innerWorker = new Worker($this->driver, $options);

        $worker = new InstrumentedWorker(
            $innerWorker,
            $this->contextProvider,
            function (ConsoleEvent $event, ?CorrelationContext $context): void {
                $this->emittedEvents[] = ['event' => $event, 'context' => $context];
            },
        );

        $worker->run('default');

        // After run(), worker should be stopped
        self::assertSame(WorkerStatus::Stopped, $worker->status());
    }

    protected function tearDown(): void
    {
        InstrumentedWorkerSuccessJob::$handled = false;
    }
}

/**
 * @internal Test double
 */
final class InstrumentedWorkerSuccessJob implements QueueableInterface
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
