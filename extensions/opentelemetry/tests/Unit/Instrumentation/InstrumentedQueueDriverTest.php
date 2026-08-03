<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Tests\Unit\Instrumentation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\OpenTelemetry\Instrumentation\InstrumentedQueueDriver;
use Pulsar\Observability\Tracing\Span;
use Pulsar\Observability\Tracing\SpanProcessorInterface;
use Pulsar\Observability\Tracing\TraceContext;
use Pulsar\Queue\JobRecord;
use Pulsar\Queue\JobRecordStatus;
use Pulsar\Queue\QueueDriverInterface;
use RuntimeException;

use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

#[CoversClass(InstrumentedQueueDriver::class)]
final class InstrumentedQueueDriverTest extends TestCase
{
    private TraceContext $traceContext;

    protected function setUp(): void
    {
        $this->traceContext = TraceContext::create();
    }

    #[Test]
    public function pushDelegatesToInnerAndCreatesSpan(): void
    {
        $inner = $this->createStub(QueueDriverInterface::class);
        $inner->method('push')->willReturn('job-001');

        [$processor, $bag] = $this->createSpanCollector();

        $driver = new InstrumentedQueueDriver(
            inner: $inner,
            processor: $processor,
            traceContext: $this->traceContext,
        );

        $jobId = $driver->push('emails', 'SendEmailJob', json_encode(['to' => 'test@example.com'], JSON_THROW_ON_ERROR));

        self::assertSame('job-001', $jobId);
        self::assertCount(1, $bag->spans);
        self::assertStringContainsString('emails publish', $bag->spans[0]->name);
    }

    #[Test]
    public function pushWhenDisabledSkipsInstrumentation(): void
    {
        $inner = $this->createStub(QueueDriverInterface::class);
        $inner->method('push')->willReturn('job-002');

        [$processor, $bag] = $this->createSpanCollector();

        $driver = new InstrumentedQueueDriver(
            inner: $inner,
            processor: $processor,
            traceContext: $this->traceContext,
            enabled: false,
        );

        $jobId = $driver->push('queue', 'Job', '{}');

        self::assertSame('job-002', $jobId);
        self::assertCount(0, $bag->spans);
    }

    #[Test]
    public function pushInjectsTraceContextIntoPayload(): void
    {
        $capturedPayload = '';
        $inner = $this->createStub(QueueDriverInterface::class);
        $inner->method('push')->willReturnCallback(
            static function (string $queue, string $jobClass, string $payload) use (&$capturedPayload): string {
                $capturedPayload = $payload;
                return 'job-003';
            },
        );

        [$processor, $bag] = $this->createSpanCollector();

        $driver = new InstrumentedQueueDriver(
            inner: $inner,
            processor: $processor,
            traceContext: $this->traceContext,
        );

        $driver->push('queue', 'Job', json_encode(['data' => 'value'], JSON_THROW_ON_ERROR));

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($capturedPayload, true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('_trace_context', $decoded);
        self::assertIsArray($decoded['_trace_context']);
        /** @var array<string, mixed> $traceCtx */
        $traceCtx = $decoded['_trace_context'];
        self::assertArrayHasKey('trace_id', $traceCtx);
        self::assertArrayHasKey('span_id', $traceCtx);
        self::assertArrayHasKey('trace_flags', $traceCtx);
    }

    #[Test]
    public function pushWithNonJsonPayloadReturnsOriginalPayload(): void
    {
        $capturedPayload = '';
        $inner = $this->createStub(QueueDriverInterface::class);
        $inner->method('push')->willReturnCallback(
            static function (string $queue, string $jobClass, string $payload) use (&$capturedPayload): string {
                $capturedPayload = $payload;
                return 'job-004';
            },
        );

        [$processor, $bag] = $this->createSpanCollector();

        $driver = new InstrumentedQueueDriver(
            inner: $inner,
            processor: $processor,
            traceContext: $this->traceContext,
        );

        $driver->push('queue', 'Job', 'not-json');

        // Non-JSON payload is sent unchanged since json_decode returns null
        self::assertSame('not-json', $capturedPayload);
    }

    #[Test]
    public function pushExceptionSetsErrorStatusAndRethrows(): void
    {
        $inner = $this->createStub(QueueDriverInterface::class);
        $inner->method('push')->willThrowException(new RuntimeException('Queue full'));

        [$processor, $bag] = $this->createSpanCollector();

        $driver = new InstrumentedQueueDriver(
            inner: $inner,
            processor: $processor,
            traceContext: $this->traceContext,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('Queue full');

        try {
            $driver->push('queue', 'Job', '{}');
        } finally {
            self::assertCount(1, $bag->spans);
        }
    }

    #[Test]
    public function popReturnsNullWhenQueueIsEmpty(): void
    {
        $inner = $this->createStub(QueueDriverInterface::class);
        $inner->method('pop')->willReturn(null);

        [$processor, $bag] = $this->createSpanCollector();

        $driver = new InstrumentedQueueDriver(
            inner: $inner,
            processor: $processor,
            traceContext: $this->traceContext,
        );

        $result = $driver->pop('empty-queue');

        self::assertNull($result);
        self::assertCount(0, $bag->spans);
    }

    #[Test]
    public function popReturnsJobAndCreatesProcessSpan(): void
    {
        $job = new JobRecord(
            id: 'job-010',
            queue: 'emails',
            jobClass: 'SendEmailJob',
            payload: json_encode(['to' => 'a@b.com'], JSON_THROW_ON_ERROR),
            attempts: 0,
            status: JobRecordStatus::Processing,
            createdAt: 1000000,
            availableAt: 1000000,
        );

        $inner = $this->createStub(QueueDriverInterface::class);
        $inner->method('pop')->willReturn($job);

        [$processor, $bag] = $this->createSpanCollector();

        $driver = new InstrumentedQueueDriver(
            inner: $inner,
            processor: $processor,
            traceContext: $this->traceContext,
        );

        $result = $driver->pop('emails');

        self::assertSame($job, $result);
        self::assertCount(1, $bag->spans);
        self::assertStringContainsString('emails process', $bag->spans[0]->name);
    }

    #[Test]
    public function popWhenDisabledSkipsInstrumentation(): void
    {
        $job = new JobRecord(
            id: 'job-011',
            queue: 'q',
            jobClass: 'Job',
            payload: '{}',
            attempts: 0,
            status: JobRecordStatus::Processing,
            createdAt: 1000000,
            availableAt: 1000000,
        );

        $inner = $this->createStub(QueueDriverInterface::class);
        $inner->method('pop')->willReturn($job);

        [$processor, $bag] = $this->createSpanCollector();

        $driver = new InstrumentedQueueDriver(
            inner: $inner,
            processor: $processor,
            traceContext: $this->traceContext,
            enabled: false,
        );

        self::assertSame($job, $driver->pop('q'));
        self::assertCount(0, $bag->spans);
    }

    #[Test]
    public function popExtractsTraceContextFromPayload(): void
    {
        $payload = json_encode([
            'data' => 'test',
            '_trace_context' => [
                'trace_id' => str_repeat('ab', 16),
                'span_id' => str_repeat('cd', 8),
                'trace_flags' => 1,
            ],
        ], JSON_THROW_ON_ERROR);

        $job = new JobRecord(
            id: 'job-012',
            queue: 'q',
            jobClass: 'Job',
            payload: $payload,
            attempts: 0,
            status: JobRecordStatus::Processing,
            createdAt: 1000000,
            availableAt: 1000000,
        );

        $inner = $this->createStub(QueueDriverInterface::class);
        $inner->method('pop')->willReturn($job);

        [$processor, $bag] = $this->createSpanCollector();

        $driver = new InstrumentedQueueDriver(
            inner: $inner,
            processor: $processor,
            traceContext: $this->traceContext,
        );

        $result = $driver->pop('q');

        self::assertNotNull($result);
        self::assertCount(1, $bag->spans);
    }

    #[Test]
    public function popHandlesPayloadWithoutTraceContext(): void
    {
        $job = new JobRecord(
            id: 'job-013',
            queue: 'q',
            jobClass: 'Job',
            payload: json_encode(['just' => 'data'], JSON_THROW_ON_ERROR),
            attempts: 0,
            status: JobRecordStatus::Processing,
            createdAt: 1000000,
            availableAt: 1000000,
        );

        $inner = $this->createStub(QueueDriverInterface::class);
        $inner->method('pop')->willReturn($job);

        [$processor, $bag] = $this->createSpanCollector();

        $driver = new InstrumentedQueueDriver(
            inner: $inner,
            processor: $processor,
            traceContext: $this->traceContext,
        );

        $result = $driver->pop('q');

        self::assertNotNull($result);
        self::assertCount(1, $bag->spans);
    }

    #[Test]
    public function popHandlesNonJsonPayload(): void
    {
        $job = new JobRecord(
            id: 'job-014',
            queue: 'q',
            jobClass: 'Job',
            payload: 'plain text',
            attempts: 0,
            status: JobRecordStatus::Processing,
            createdAt: 1000000,
            availableAt: 1000000,
        );

        $inner = $this->createStub(QueueDriverInterface::class);
        $inner->method('pop')->willReturn($job);

        [$processor, $bag] = $this->createSpanCollector();

        $driver = new InstrumentedQueueDriver(
            inner: $inner,
            processor: $processor,
            traceContext: $this->traceContext,
        );

        $result = $driver->pop('q');

        self::assertNotNull($result);
        self::assertCount(1, $bag->spans);
    }

    #[Test]
    public function popHandlesInvalidTraceContextShape(): void
    {
        $payload = json_encode([
            '_trace_context' => 'not-an-array',
        ], JSON_THROW_ON_ERROR);

        $job = new JobRecord(
            id: 'job-015',
            queue: 'q',
            jobClass: 'Job',
            payload: $payload,
            attempts: 0,
            status: JobRecordStatus::Processing,
            createdAt: 1000000,
            availableAt: 1000000,
        );

        $inner = $this->createStub(QueueDriverInterface::class);
        $inner->method('pop')->willReturn($job);

        [$processor, $bag] = $this->createSpanCollector();

        $driver = new InstrumentedQueueDriver(
            inner: $inner,
            processor: $processor,
            traceContext: $this->traceContext,
        );

        $result = $driver->pop('q');

        self::assertNotNull($result);
        self::assertCount(1, $bag->spans);
    }

    #[Test]
    public function acknowledgeDelegatesToInner(): void
    {
        $inner = $this->createMock(QueueDriverInterface::class);
        $inner->expects(self::once())->method('acknowledge')->with('job-100');

        $processor = $this->createStub(SpanProcessorInterface::class);

        $driver = new InstrumentedQueueDriver(
            inner: $inner,
            processor: $processor,
            traceContext: $this->traceContext,
        );

        $driver->acknowledge('job-100');
    }

    #[Test]
    public function rejectDelegatesToInner(): void
    {
        $inner = $this->createMock(QueueDriverInterface::class);
        $inner->expects(self::once())->method('reject')->with('job-101', 'timeout');

        $processor = $this->createStub(SpanProcessorInterface::class);

        $driver = new InstrumentedQueueDriver(
            inner: $inner,
            processor: $processor,
            traceContext: $this->traceContext,
        );

        $driver->reject('job-101', 'timeout');
    }

    #[Test]
    public function sizeDelegatesToInner(): void
    {
        $inner = $this->createStub(QueueDriverInterface::class);
        $inner->method('size')->willReturn(42);

        $processor = $this->createStub(SpanProcessorInterface::class);

        $driver = new InstrumentedQueueDriver(
            inner: $inner,
            processor: $processor,
            traceContext: $this->traceContext,
        );

        self::assertSame(42, $driver->size('emails'));
    }

    #[Test]
    public function purgeDelegatesToInner(): void
    {
        $inner = $this->createStub(QueueDriverInterface::class);
        $inner->method('purge')->willReturn(10);

        $processor = $this->createStub(SpanProcessorInterface::class);

        $driver = new InstrumentedQueueDriver(
            inner: $inner,
            processor: $processor,
            traceContext: $this->traceContext,
        );

        self::assertSame(10, $driver->purge('dead-letters'));
    }

    #[Test]
    public function findByStatusDelegatesToInner(): void
    {
        $inner = $this->createStub(QueueDriverInterface::class);
        $inner->method('findByStatus')->willReturn([]);

        $processor = $this->createStub(SpanProcessorInterface::class);

        $driver = new InstrumentedQueueDriver(
            inner: $inner,
            processor: $processor,
            traceContext: $this->traceContext,
        );

        self::assertSame([], $driver->findByStatus(JobRecordStatus::Failed));
    }

    /**
     * Creates a span processor that collects all spans passed to onEnd().
     *
     * @return array{SpanProcessorInterface, SpanCollectorBag}
     */
    private function createSpanCollector(): array
    {
        $bag = new SpanCollectorBag();
        $processor = new class ($bag) implements SpanProcessorInterface {
            public function __construct(private readonly SpanCollectorBag $bag) {}

            public function onEnd(Span $span): void
            {
                $this->bag->spans[] = $span;
            }
        };

        return [$processor, $bag];
    }
}

/**
 * Mutable container for collected spans, used to avoid by-ref array issues.
 */
final class SpanCollectorBag
{
    /** @var list<Span> */
    public array $spans = [];
}
