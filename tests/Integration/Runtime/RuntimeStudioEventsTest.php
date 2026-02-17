<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Runtime;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Collector\InstrumentedRuntime;
use Pulsar\Extension\Studio\Console\Event\ConsoleEvent;
use Pulsar\Extension\Studio\Console\Event\EventType;
use Pulsar\Extension\Studio\Console\Event\Payload\RuntimeLeakWarningPayload;
use Pulsar\Extension\Studio\Console\Event\Payload\RuntimeRequestCompletePayload;
use Pulsar\Extension\Studio\Console\Event\Payload\RuntimeSchedulerMetricPayload;
use Pulsar\Extension\Studio\Console\Event\Payload\RuntimeWorkerRecyclePayload;
use Pulsar\Extension\Studio\Console\Event\Payload\RuntimeWorkerStartPayload;
use Pulsar\Extension\Studio\FiberScopedContextProvider;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\ResponseStatus;
use Pulsar\Observability\Context\CorrelationContext;
use Pulsar\Observability\Metrics\MetricRegistry;
use Pulsar\Runtime\FpmRuntime;

#[CoversClass(InstrumentedRuntime::class)]
final class RuntimeStudioEventsTest extends TestCase
{
    /** @var list<ConsoleEvent> */
    private array $emittedEvents = [];
    private InstrumentedRuntime $collector;
    private MetricRegistry $metricRegistry;

    protected function setUp(): void
    {
        $this->emittedEvents = [];
        $this->metricRegistry = new MetricRegistry();
        $contextProvider = new FiberScopedContextProvider();

        $inner = new FpmRuntime(new \Pulsar\Core\Kernel());

        $this->collector = new InstrumentedRuntime(
            inner: $inner,
            contextProvider: $contextProvider,
            metricRegistry: $this->metricRegistry,
            emit: Closure::fromCallable(function (ConsoleEvent $event, ?CorrelationContext $ctx): void {
                $this->emittedEvents[] = $event;
            }),
        );
    }

    #[Test]
    public function it_emits_worker_start_event(): void
    {
        $this->collector->emitWorkerStart(
            host: '127.0.0.1',
            port: 8080,
            fiberConcurrency: 16,
            maxRequests: 10000,
            memoryThresholdMb: 256,
        );

        self::assertCount(1, $this->emittedEvents);
        self::assertInstanceOf(RuntimeWorkerStartPayload::class, $this->emittedEvents[0]);
        self::assertSame(EventType::RuntimeWorkerStart, $this->emittedEvents[0]->eventType());
    }

    #[Test]
    public function it_emits_request_complete_event(): void
    {
        $request = new ServerRequest(
            method: 'GET',
            uri: '/api/users',
        );

        $response = new Response(statusCode: ResponseStatus::OK->value, body: '[]');

        $this->collector->recordRequest($request, $response, 12.5, 1024);

        self::assertCount(1, $this->emittedEvents);
        $event = $this->emittedEvents[0];
        self::assertInstanceOf(RuntimeRequestCompletePayload::class, $event);
        self::assertSame('GET', $event->method);
        self::assertSame('/api/users', $event->path);
    }

    #[Test]
    public function it_emits_worker_recycle_event(): void
    {
        $this->collector->emitWorkerRecycle(
            reason: 'max_requests',
            requestCount: 10000,
            memoryUsageMb: 200,
            uptimeSeconds: 3600,
        );

        self::assertCount(1, $this->emittedEvents);
        self::assertInstanceOf(RuntimeWorkerRecyclePayload::class, $this->emittedEvents[0]);
        self::assertSame('max_requests', $this->emittedEvents[0]->reason);
    }

    #[Test]
    public function it_emits_leak_warning_event(): void
    {
        $this->collector->emitLeakWarning(
            warnings: ['Unreleased resource: conn-1'],
            memoryDeltaBytes: 2_097_152,
            requestNumber: 42,
        );

        self::assertCount(1, $this->emittedEvents);
        self::assertInstanceOf(RuntimeLeakWarningPayload::class, $this->emittedEvents[0]);
        self::assertSame(42, $this->emittedEvents[0]->requestNumber);
    }

    #[Test]
    public function it_emits_scheduler_metric_event(): void
    {
        $this->collector->emitSchedulerMetric(
            activeFibers: 5,
            uptimeSeconds: 120.5,
        );

        self::assertCount(1, $this->emittedEvents);
        self::assertInstanceOf(RuntimeSchedulerMetricPayload::class, $this->emittedEvents[0]);
        self::assertSame(5, $this->emittedEvents[0]->activeFibers);
    }

    #[Test]
    public function it_registers_metrics_in_registry(): void
    {
        // Metrics are registered in the constructor
        self::assertTrue($this->metricRegistry->has('runtime_requests_total'));
        self::assertTrue($this->metricRegistry->has('runtime_request_duration_ms'));
        self::assertTrue($this->metricRegistry->has('runtime_memory_bytes'));
        self::assertTrue($this->metricRegistry->has('runtime_worker_restarts_total'));
        self::assertTrue($this->metricRegistry->has('runtime_active_fibers'));
        self::assertTrue($this->metricRegistry->has('runtime_slow_requests_total'));
    }

    #[Test]
    public function it_increments_request_counter_on_record(): void
    {
        $request = new ServerRequest(
            method: 'GET',
            uri: '/',
        );

        $this->collector->recordRequest($request, new Response(), 5.0, 0);
        $this->collector->recordRequest($request, new Response(), 10.0, 0);

        // Counter should have been incremented twice
        $counter = $this->metricRegistry->counter('runtime_requests_total');
        self::assertSame(2.0, $counter->value());
    }

    #[Test]
    public function it_does_not_emit_when_disabled(): void
    {
        $this->collector->enabled = false;

        $this->collector->emitWorkerStart('127.0.0.1', 8080, 0, 10000, 256);

        self::assertEmpty($this->emittedEvents);
    }

    #[Test]
    public function it_tracks_slow_requests(): void
    {
        $request = new ServerRequest(
            method: 'GET',
            uri: '/slow',
        );

        // Record a slow request (>1000ms)
        $this->collector->recordRequest($request, new Response(), 1500.0, 0);

        $counter = $this->metricRegistry->counter('runtime_slow_requests_total');
        self::assertSame(1.0, $counter->value());
    }
}
