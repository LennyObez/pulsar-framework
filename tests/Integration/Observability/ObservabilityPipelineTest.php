<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Observability;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Observability\Metrics\Counter;
use Pulsar\Observability\Metrics\Exception\MetricsException;
use Pulsar\Observability\Metrics\Gauge;
use Pulsar\Observability\Metrics\Histogram;
use Pulsar\Observability\Metrics\LabelSet;
use Pulsar\Observability\Metrics\MetricRegistry;
use Pulsar\Observability\Tracing\InMemorySpanCollector;
use Pulsar\Observability\Tracing\Span;
use Pulsar\Observability\Tracing\SpanStatus;
use Pulsar\Observability\Tracing\TraceContext;

#[CoversClass(Counter::class)]
#[CoversClass(Gauge::class)]
#[CoversClass(Histogram::class)]
#[CoversClass(MetricRegistry::class)]
#[CoversClass(LabelSet::class)]
#[CoversClass(Span::class)]
#[CoversClass(InMemorySpanCollector::class)]
final class ObservabilityPipelineTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Counter
    // -------------------------------------------------------------------------

    #[Test]
    public function counterIncrementAccumulates(): void
    {
        $counter = new Counter('http.requests');
        $labels = new LabelSet(['method' => 'GET', 'status' => '200']);

        $counter->increment($labels);
        $counter->increment($labels);
        $counter->increment($labels, 3.0);

        self::assertSame(5.0, $counter->value($labels));
    }

    #[Test]
    public function counterDifferentLabelsTrackedSeparately(): void
    {
        $counter = new Counter('http.requests');
        $get = new LabelSet(['method' => 'GET']);
        $post = new LabelSet(['method' => 'POST']);

        $counter->increment($get, 10.0);
        $counter->increment($post, 3.0);

        self::assertSame(10.0, $counter->value($get));
        self::assertSame(3.0, $counter->value($post));
        self::assertCount(2, $counter->values());
    }

    #[Test]
    public function counterRejectNegativeIncrement(): void
    {
        $counter = new Counter('errors');

        $this->expectException(MetricsException::class);
        $counter->increment(new LabelSet(), -1.0);
    }

    #[Test]
    public function counterResetClearsAllValues(): void
    {
        $counter = new Counter('events');
        $counter->increment(new LabelSet(['type' => 'click']), 5.0);
        $counter->reset();

        self::assertSame([], $counter->values());
    }

    // -------------------------------------------------------------------------
    // Gauge
    // -------------------------------------------------------------------------

    #[Test]
    public function gaugeSetAndGet(): void
    {
        $gauge = new Gauge('memory.usage');
        $labels = new LabelSet(['unit' => 'bytes']);

        $gauge->set(1024.0, $labels);

        self::assertSame(1024.0, $gauge->value($labels));
    }

    #[Test]
    public function gaugeIncrementAndDecrement(): void
    {
        $gauge = new Gauge('queue.depth');
        $labels = new LabelSet();

        $gauge->set(10.0, $labels);
        $gauge->increment($labels, 5.0);
        $gauge->decrement($labels, 3.0);

        self::assertSame(12.0, $gauge->value($labels));
    }

    // -------------------------------------------------------------------------
    // Histogram
    // -------------------------------------------------------------------------

    #[Test]
    public function histogramObservesAndTracksCountAndSum(): void
    {
        $histogram = new Histogram('http.duration', boundaries: [0.1, 0.5, 1.0]);
        $labels = new LabelSet(['route' => '/api/users']);

        $histogram->observe(0.05, $labels);
        $histogram->observe(0.3, $labels);
        $histogram->observe(0.9, $labels);

        self::assertSame(3, $histogram->count($labels));
        self::assertEqualsWithDelta(1.25, $histogram->sum($labels), 0.001);
    }

    #[Test]
    public function histogramSeriesCount(): void
    {
        $histogram = new Histogram('latency', boundaries: [0.1, 1.0]);
        $histogram->observe(0.05, new LabelSet(['endpoint' => '/a']));
        $histogram->observe(0.5, new LabelSet(['endpoint' => '/b']));

        self::assertSame(2, $histogram->seriesCount());
    }

    // -------------------------------------------------------------------------
    // MetricRegistry
    // -------------------------------------------------------------------------

    #[Test]
    public function registryCreateOrReturnSameMetric(): void
    {
        $registry = new MetricRegistry();

        $c1 = $registry->counter('requests');
        $c2 = $registry->counter('requests');

        self::assertSame($c1, $c2);
    }

    #[Test]
    public function registryTypeMismatchThrows(): void
    {
        $registry = new MetricRegistry();
        $registry->counter('metric.name');

        $this->expectException(MetricsException::class);
        $registry->gauge('metric.name'); // wrong type
    }

    #[Test]
    public function registryHasAndTypeOf(): void
    {
        $registry = new MetricRegistry();
        $registry->counter('hits');

        self::assertTrue($registry->has('hits'));
        self::assertFalse($registry->has('misses'));
    }

    #[Test]
    public function registryAllReturnsAllMetrics(): void
    {
        $registry = new MetricRegistry();
        $registry->counter('a');
        $registry->gauge('b');
        $registry->histogram('c');

        self::assertCount(3, $registry->all());
    }

    // -------------------------------------------------------------------------
    // Span + InMemorySpanCollector
    // -------------------------------------------------------------------------

    #[Test]
    public function spanLifecycleStartsUnendedAndEndsWithDuration(): void
    {
        $ctx = TraceContext::create();
        $span = new Span('db.query', $ctx);

        self::assertFalse($span->hasEnded());

        $span->end();

        self::assertTrue($span->hasEnded());
        self::assertGreaterThanOrEqual(0.0, $span->durationSeconds());
    }

    #[Test]
    public function spanEndIsIdempotent(): void
    {
        $ctx = TraceContext::create();
        $span = new Span('task', $ctx);
        $span->end();
        $dur1 = $span->durationSeconds();

        $span->end(); // second end is no-op
        $dur2 = $span->durationSeconds();

        self::assertSame($dur1, $dur2);
    }

    #[Test]
    public function spanAttributesStoredAndRetrieved(): void
    {
        $ctx = TraceContext::create();
        $span = new Span('http.request', $ctx);
        $span->setAttribute('http.method', 'GET');
        $span->setAttribute('http.status_code', 200);
        $span->end();

        self::assertSame('GET', $span->attributes()['http.method']);
        self::assertSame(200, $span->attributes()['http.status_code']);
    }

    #[Test]
    public function spanStatusDefaultsToUnset(): void
    {
        $ctx = TraceContext::create();
        $span = new Span('work', $ctx);

        self::assertSame(SpanStatus::Unset, $span->status);
    }

    #[Test]
    public function traceContextChildPreservesTraceId(): void
    {
        $parent = TraceContext::create();
        $child = $parent->createChild();

        self::assertSame($parent->traceId->value, $child->traceId->value);
        self::assertNotSame($parent->spanId->value, $child->spanId->value);
    }

    #[Test]
    public function inMemoryCollectorStoresEndedSpans(): void
    {
        $collector = new InMemorySpanCollector();
        $ctx = TraceContext::create();

        $span = new Span('test.span', $ctx);
        $span->end();
        $collector->onEnd($span);

        self::assertSame(1, $collector->count());
        self::assertSame($span, $collector->spans()[0]);
    }

    #[Test]
    public function inMemoryCollectorFiltersByTraceId(): void
    {
        $collector = new InMemorySpanCollector();

        $ctx1 = TraceContext::create();
        $span1 = new Span('span-1', $ctx1);
        $span1->end();
        $collector->onEnd($span1);

        $ctx2 = TraceContext::create();
        $span2 = new Span('span-2', $ctx2);
        $span2->end();
        $collector->onEnd($span2);

        $byTrace1 = $collector->spansByTraceId($ctx1->traceId);
        self::assertCount(1, $byTrace1);
        self::assertSame('span-1', $byTrace1[0]->name);
    }

    #[Test]
    public function inMemoryCollectorClearResetsAll(): void
    {
        $collector = new InMemorySpanCollector();
        $ctx = TraceContext::create();

        $span = new Span('x', $ctx);
        $span->end();
        $collector->onEnd($span);

        $collector->clear();

        self::assertSame(0, $collector->count());
        self::assertSame([], $collector->spans());
    }

    #[Test]
    public function fullObservabilityPipeline(): void
    {
        // Simulate a request pipeline that uses metrics + tracing together
        $registry = new MetricRegistry();
        $collector = new InMemorySpanCollector();

        $rootCtx = TraceContext::create();
        $requestSpan = new Span('HTTP GET /api/orders', $rootCtx);

        // Record a counter for request
        $requests = $registry->counter('http.requests.total');
        $requests->increment(new LabelSet(['method' => 'GET', 'route' => '/api/orders']));

        // Simulate a DB sub-span
        $dbCtx = $rootCtx->createChild();
        $dbSpan = new Span('db.query', $dbCtx);
        $dbSpan->setAttribute('db.statement', 'SELECT * FROM orders');
        $dbSpan->end();
        $collector->onEnd($dbSpan);

        // End root span
        $requestSpan->setAttribute('http.status_code', 200);
        $requestSpan->end();
        $collector->onEnd($requestSpan);

        // Record histogram for request duration
        $histogram = $registry->histogram('http.request.duration');
        $duration = $requestSpan->durationSeconds();
        self::assertNotNull($duration);
        $histogram->observe($duration, new LabelSet(['route' => '/api/orders']));

        // Verify everything was recorded
        self::assertSame(
            1.0,
            $requests->value(new LabelSet(['method' => 'GET', 'route' => '/api/orders'])),
        );
        self::assertSame(2, $collector->count());

        $traceSpans = $collector->spansByTraceId($rootCtx->traceId);
        self::assertCount(2, $traceSpans); // both spans share same traceId

        self::assertSame(1, $histogram->count(new LabelSet(['route' => '/api/orders'])));
    }
}
