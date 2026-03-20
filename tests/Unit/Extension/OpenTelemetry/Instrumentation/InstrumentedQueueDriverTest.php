<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\OpenTelemetry\Instrumentation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\OpenTelemetry\Instrumentation\InstrumentedQueueDriver;
use Pulsar\Observability\Tracing\Span;
use Pulsar\Observability\Tracing\SpanId;
use Pulsar\Observability\Tracing\SpanProcessorInterface;
use Pulsar\Observability\Tracing\SpanStatus;
use Pulsar\Observability\Tracing\TraceContext;
use Pulsar\Observability\Tracing\TraceId;
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
        $this->traceContext = new TraceContext(
            new TraceId('0af7651916cd43dd8448eb211c80319c'),
            new SpanId('b7ad6b7169203331'),
        );
    }

    #[Test]
    public function pushCreatesPublishSpan(): void
    {
        $capturedSpan = null;
        $processor = $this->createMock(SpanProcessorInterface::class);
        $processor->expects(self::once())
            ->method('onEnd')
            ->with(self::callback(static function (Span $span) use (&$capturedSpan): bool {
                $capturedSpan = $span;
                return true;
            }));

        $inner = $this->createMock(QueueDriverInterface::class);
        $inner->expects(self::once())
            ->method('push')
            ->willReturn('job-123');

        $driver = new InstrumentedQueueDriver($inner, $processor, $this->traceContext);
        $jobId = $driver->push('emails', 'SendEmail', json_encode(['to' => 'user@test.com'], JSON_THROW_ON_ERROR));

        self::assertSame('job-123', $jobId);
        self::assertNotNull($capturedSpan);
        self::assertSame('emails publish', $capturedSpan->name);
        self::assertSame('pulsar.queue', $capturedSpan->attributes()['messaging.system']);
        self::assertSame('emails', $capturedSpan->attributes()['messaging.destination']);
        self::assertSame('publish', $capturedSpan->attributes()['messaging.operation']);
        self::assertSame('SendEmail', $capturedSpan->attributes()['messaging.job_class']);
        self::assertSame('job-123', $capturedSpan->attributes()['messaging.message_id']);
        self::assertSame(SpanStatus::Ok, $capturedSpan->status);
    }

    #[Test]
    public function pushInjectsTraceContextInPayload(): void
    {
        $capturedPayload = null;
        $processor = $this->createStub(SpanProcessorInterface::class);

        $inner = $this->createMock(QueueDriverInterface::class);
        $inner->expects(self::once())
            ->method('push')
            ->with(
                'queue',
                'Job',
                self::callback(static function (string $payload) use (&$capturedPayload): bool {
                    $capturedPayload = $payload;
                    return true;
                }),
            )
            ->willReturn('id');

        $driver = new InstrumentedQueueDriver($inner, $processor, $this->traceContext);
        $driver->push('queue', 'Job', json_encode(['data' => 'value'], JSON_THROW_ON_ERROR));

        self::assertNotNull($capturedPayload);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($capturedPayload, true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('_trace_context', $decoded);
        /** @var array<string, mixed> $traceCtx */
        $traceCtx = $decoded['_trace_context'];
        self::assertArrayHasKey('trace_id', $traceCtx);
        self::assertArrayHasKey('span_id', $traceCtx);
        self::assertArrayHasKey('trace_flags', $traceCtx);
        self::assertSame('value', $decoded['data']);
    }

    #[Test]
    public function popCreatesProcessSpan(): void
    {
        $capturedSpan = null;
        $processor = $this->createMock(SpanProcessorInterface::class);
        $processor->expects(self::once())
            ->method('onEnd')
            ->with(self::callback(static function (Span $span) use (&$capturedSpan): bool {
                $capturedSpan = $span;
                return true;
            }));

        $job = new JobRecord(
            id: 'job-456',
            queue: 'emails',
            jobClass: 'SendEmail',
            payload: '{}',
            attempts: 1,
            status: JobRecordStatus::Processing,
            createdAt: 1000000,
            availableAt: 1000000,
        );

        $inner = $this->createStub(QueueDriverInterface::class);
        $inner->method('pop')->willReturn($job);

        $driver = new InstrumentedQueueDriver($inner, $processor, $this->traceContext);
        $result = $driver->pop('emails');

        self::assertSame($job, $result);
        self::assertNotNull($capturedSpan);
        self::assertSame('emails process', $capturedSpan->name);
        self::assertSame('process', $capturedSpan->attributes()['messaging.operation']);
        self::assertSame('job-456', $capturedSpan->attributes()['messaging.message_id']);
    }

    #[Test]
    public function popReturnsNullWithoutSpan(): void
    {
        $processor = $this->createMock(SpanProcessorInterface::class);
        $processor->expects(self::never())->method('onEnd');

        $inner = $this->createStub(QueueDriverInterface::class);
        $inner->method('pop')->willReturn(null);

        $driver = new InstrumentedQueueDriver($inner, $processor, $this->traceContext);
        $result = $driver->pop('empty-queue');

        self::assertNull($result);
    }

    #[Test]
    public function disabledPassesThroughWithoutSpan(): void
    {
        $processor = $this->createMock(SpanProcessorInterface::class);
        $processor->expects(self::never())->method('onEnd');

        $inner = $this->createMock(QueueDriverInterface::class);
        $inner->expects(self::once())->method('push')->willReturn('job-id');

        $driver = new InstrumentedQueueDriver(
            inner: $inner,
            processor: $processor,
            traceContext: $this->traceContext,
            enabled: false,
        );

        $driver->push('queue', 'Job', '{}');
    }

    #[Test]
    public function pushErrorSetsSpanStatusToError(): void
    {
        $capturedSpan = null;
        $processor = $this->createMock(SpanProcessorInterface::class);
        $processor->expects(self::once())
            ->method('onEnd')
            ->with(self::callback(static function (Span $span) use (&$capturedSpan): bool {
                $capturedSpan = $span;
                return true;
            }));

        $inner = $this->createStub(QueueDriverInterface::class);
        $inner->method('push')->willThrowException(new RuntimeException('Queue full'));

        $driver = new InstrumentedQueueDriver($inner, $processor, $this->traceContext);

        try {
            $driver->push('queue', 'Job', '{}');
        } catch (RuntimeException) {
            // Expected
        }

        self::assertNotNull($capturedSpan);
        self::assertSame(SpanStatus::Error, $capturedSpan->status);
        self::assertSame('RuntimeException (code: 0)', $capturedSpan->attributes()['exception.message']);
    }

    #[Test]
    public function delegatesNonInstrumentedMethods(): void
    {
        $processor = $this->createStub(SpanProcessorInterface::class);

        $inner = $this->createMock(QueueDriverInterface::class);
        $inner->expects(self::once())->method('acknowledge')->with('job-1');
        $inner->expects(self::once())->method('reject')->with('job-2', 'failed');
        $inner->expects(self::once())->method('size')->with('queue')->willReturn(5);
        $inner->expects(self::once())->method('purge')->with('queue')->willReturn(3);
        $inner->expects(self::once())->method('findByStatus')
            ->with(JobRecordStatus::Failed)
            ->willReturn([]);

        $driver = new InstrumentedQueueDriver($inner, $processor, $this->traceContext);

        $driver->acknowledge('job-1');
        $driver->reject('job-2', 'failed');
        self::assertSame(5, $driver->size('queue'));
        self::assertSame(3, $driver->purge('queue'));
        self::assertSame([], $driver->findByStatus(JobRecordStatus::Failed));
    }
}
