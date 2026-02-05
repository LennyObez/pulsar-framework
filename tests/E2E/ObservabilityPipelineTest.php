<?php

declare(strict_types=1);

namespace Pulsar\Tests\E2E;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Core\Kernel;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Method;
use Pulsar\Http\Middleware\MetricsMiddleware;
use Pulsar\Http\Middleware\TracingMiddleware;
use Pulsar\Http\Request;
use Pulsar\Http\Response;
use Pulsar\Http\ResponseStatus;
use Pulsar\Observability\Log\LogEntry;
use Pulsar\Observability\Log\Logger;
use Pulsar\Observability\Log\LogLevel;
use Pulsar\Observability\Log\LogSinkInterface;
use Pulsar\Observability\Metrics\Counter;
use Pulsar\Observability\Metrics\Histogram;
use Pulsar\Observability\Metrics\LabelSet;
use Pulsar\Observability\Metrics\MetricRegistry;
use Pulsar\Observability\Tracing\InMemorySpanCollector;
use Pulsar\Observability\Tracing\Span;
use Pulsar\Observability\Tracing\SpanStatus;
use Pulsar\Observability\Tracing\TraceContext;

/**
 * End-to-end tests for the observability pipeline.
 *
 * Verifies that metrics collection, structured logging, and tracing
 * work correctly as standalone services and through middleware integration.
 */
#[CoversClass(MetricRegistry::class)]
#[CoversClass(MetricsMiddleware::class)]
#[CoversClass(TracingMiddleware::class)]
#[CoversClass(InMemorySpanCollector::class)]
#[CoversClass(Logger::class)]
final class ObservabilityPipelineTest extends TestCase
{
    private function createRequest(
        Method $method = Method::GET,
        string $path = '/',
        HeaderBag $headers = new HeaderBag(),
    ): Request {
        return new Request(
            method: $method,
            uri: $path,
            path: $path,
            queryString: '',
            headers: $headers,
            body: '',
        );
    }

    // ---- Metrics ----

    #[Test]
    public function metricRegistryRecordsCounterIncrements(): void
    {
        $registry = new MetricRegistry();
        $counter = $registry->counter('http_requests_total', 'Total HTTP requests');

        $labels = new LabelSet(['method' => 'GET', 'path' => '/', 'status' => '200']);
        $counter->increment($labels);
        $counter->increment($labels);
        $counter->increment($labels);

        self::assertSame(3.0, $counter->value($labels));
    }

    #[Test]
    public function metricRegistryRecordsHistogramObservations(): void
    {
        $registry = new MetricRegistry();
        $histogram = $registry->histogram('request_duration_seconds', 'Request duration');

        $labels = new LabelSet(['method' => 'GET', 'path' => '/']);
        $histogram->observe(0.05, $labels);
        $histogram->observe(0.12, $labels);
        $histogram->observe(0.003, $labels);

        self::assertSame(3, $histogram->count($labels));
        self::assertEqualsWithDelta(0.173, $histogram->sum($labels), 0.0001);
    }

    #[Test]
    public function metricsMiddlewareRecordsRequestMetrics(): void
    {
        $registry = new MetricRegistry();
        $middleware = new MetricsMiddleware($registry);

        $request = $this->createRequest(Method::GET, '/api/users');

        $response = $middleware->process(
            $request,
            fn(Request $req): Response => Response::json(['users' => []]),
        );

        self::assertSame(ResponseStatus::OK, $response->status);

        // Verify request counter was incremented
        self::assertTrue($registry->has('pulsar_http_requests_total'));
        $counter = $registry->counter('pulsar_http_requests_total');
        $labels = new LabelSet(['method' => 'GET', 'path' => '/api/users', 'status' => '200']);
        self::assertSame(1.0, $counter->value($labels));

        // Verify duration histogram was recorded
        self::assertTrue($registry->has('pulsar_http_request_duration_seconds'));
        $histogram = $registry->histogram('pulsar_http_request_duration_seconds');
        $durationLabels = new LabelSet(['method' => 'GET', 'path' => '/api/users']);
        self::assertSame(1, $histogram->count($durationLabels));
        self::assertGreaterThan(0.0, $histogram->sum($durationLabels));
    }

    #[Test]
    public function metricsMiddlewareTracksMultipleRequests(): void
    {
        $registry = new MetricRegistry();
        $middleware = new MetricsMiddleware($registry);

        // Simulate 3 GET requests
        for ($i = 0; $i < 3; $i++) {
            $middleware->process(
                $this->createRequest(Method::GET, '/health'),
                fn(Request $req): Response => Response::text('ok'),
            );
        }

        $counter = $registry->counter('pulsar_http_requests_total');
        $labels = new LabelSet(['method' => 'GET', 'path' => '/health', 'status' => '200']);
        self::assertSame(3.0, $counter->value($labels));
    }

    #[Test]
    public function metricRegistryDistinguishesMetricsByLabels(): void
    {
        $registry = new MetricRegistry();
        $counter = $registry->counter('api_calls', 'API call counter');

        $getLabels = new LabelSet(['method' => 'GET']);
        $postLabels = new LabelSet(['method' => 'POST']);

        $counter->increment($getLabels, 5.0);
        $counter->increment($postLabels, 2.0);

        self::assertSame(5.0, $counter->value($getLabels));
        self::assertSame(2.0, $counter->value($postLabels));
    }

    // ---- Tracing ----

    #[Test]
    public function tracingMiddlewareCreatesRootSpanForRequest(): void
    {
        $collector = new InMemorySpanCollector();
        $middleware = new TracingMiddleware($collector, 1.0);

        $request = $this->createRequest(Method::GET, '/api/items');

        $response = $middleware->process(
            $request,
            fn(Request $req): Response => Response::text('traced'),
        );

        self::assertSame(ResponseStatus::OK, $response->status);
        self::assertSame('traced', $response->body);

        // Verify span was collected
        self::assertSame(1, $collector->count());
        $spans = $collector->spans();
        $span = $spans[0];

        self::assertSame('HTTP GET /api/items', $span->name);
        self::assertTrue($span->hasEnded());
        self::assertSame(SpanStatus::Ok, $span->status);

        // Verify span attributes
        $attributes = $span->attributes();
        self::assertSame('GET', $attributes['http.method']);
        self::assertSame('/api/items', $attributes['http.path']);
        self::assertSame(200, $attributes['http.status_code']);
    }

    #[Test]
    public function tracingMiddlewarePropagatesToResponseHeader(): void
    {
        $collector = new InMemorySpanCollector();
        $middleware = new TracingMiddleware($collector, 1.0);

        $response = $middleware->process(
            $this->createRequest(),
            fn(Request $req): Response => Response::text('ok'),
        );

        $traceparent = $response->headers->first('traceparent');
        self::assertNotNull($traceparent);
        self::assertMatchesRegularExpression(
            '/^00-[a-f0-9]{32}-[a-f0-9]{16}-[a-f0-9]{2}$/',
            $traceparent,
        );
    }

    #[Test]
    public function tracingMiddlewarePropagatesIncomingTraceContext(): void
    {
        $collector = new InMemorySpanCollector();
        $middleware = new TracingMiddleware($collector, 1.0);

        $incomingTraceparent = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01';
        $request = $this->createRequest(
            headers: new HeaderBag(['traceparent' => $incomingTraceparent]),
        );

        $response = $middleware->process(
            $request,
            fn(Request $req): Response => Response::text('propagated'),
        );

        // Verify the span uses the incoming trace ID
        $spans = $collector->spans();
        self::assertCount(1, $spans);

        $span = $spans[0];
        self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $span->context->traceId->value);

        // Response should contain traceparent with same trace ID
        $responseTraceparent = $response->headers->first('traceparent');
        self::assertNotNull($responseTraceparent);
        self::assertStringContainsString('4bf92f3577b34da6a3ce929d0e0e4736', $responseTraceparent);
    }

    #[Test]
    public function tracingMiddlewareAttachesContextToRequestAttributes(): void
    {
        $collector = new InMemorySpanCollector();
        $middleware = new TracingMiddleware($collector, 1.0);

        $capturedContext = null;
        $capturedSpan = null;

        $middleware->process(
            $this->createRequest(),
            function (Request $req) use (&$capturedContext, &$capturedSpan): Response {
                $capturedContext = $req->attribute('_trace_context');
                $capturedSpan = $req->attribute('_root_span');
                return Response::text('ok');
            },
        );

        self::assertInstanceOf(TraceContext::class, $capturedContext);
        self::assertInstanceOf(Span::class, $capturedSpan);
    }

    #[Test]
    public function tracingMiddlewareSetsErrorStatusOnServerError(): void
    {
        $collector = new InMemorySpanCollector();
        $middleware = new TracingMiddleware($collector, 1.0);

        $middleware->process(
            $this->createRequest(),
            fn(Request $req): Response => new Response(
                body: 'Server Error',
                status: ResponseStatus::InternalServerError,
            ),
        );

        $spans = $collector->spans();
        self::assertCount(1, $spans);
        self::assertSame(SpanStatus::Error, $spans[0]->status);
    }

    #[Test]
    public function spanCollectorEvictsOldSpansWhenOverCapacity(): void
    {
        $collector = new InMemorySpanCollector(maxSpans: 3);

        for ($i = 0; $i < 5; $i++) {
            $context = TraceContext::create();
            $span = new Span("span-{$i}", $context);
            $span->end();
            $collector->onEnd($span);
        }

        self::assertSame(3, $collector->count());
        $spans = $collector->spans();
        self::assertSame('span-2', $spans[0]->name);
        self::assertSame('span-3', $spans[1]->name);
        self::assertSame('span-4', $spans[2]->name);
    }

    // ---- Logging ----

    #[Test]
    public function loggerDispatchesEntriesToSinks(): void
    {
        $sink = new CollectingLogSink();
        $logger = new Logger([$sink], LogLevel::Debug, 'test');

        $logger->info('Request received', ['path' => '/api/data']);
        $logger->warning('Slow query detected', ['duration_ms' => 450]);
        $logger->error('Connection failed', ['host' => 'db.example.com']);

        $entries = $sink->entries();
        self::assertCount(3, $entries);

        self::assertSame(LogLevel::Info, $entries[0]->level);
        self::assertSame('Request received', $entries[0]->message);
        self::assertSame('/api/data', $entries[0]->context['path']);

        self::assertSame(LogLevel::Warning, $entries[1]->level);
        self::assertSame('Slow query detected', $entries[1]->message);

        self::assertSame(LogLevel::Error, $entries[2]->level);
        self::assertSame('Connection failed', $entries[2]->message);
    }

    #[Test]
    public function loggerRespectsLevelThreshold(): void
    {
        $sink = new CollectingLogSink();
        $logger = new Logger([$sink], LogLevel::Warning, 'test');

        $logger->debug('Debug message');
        $logger->info('Info message');
        $logger->warning('Warning message');
        $logger->error('Error message');

        $entries = $sink->entries();
        self::assertCount(2, $entries);
        self::assertSame(LogLevel::Warning, $entries[0]->level);
        self::assertSame(LogLevel::Error, $entries[1]->level);
    }

    #[Test]
    public function loggerSetsChannelOnEntries(): void
    {
        $sink = new CollectingLogSink();
        $logger = new Logger([$sink], LogLevel::Debug, 'security');

        $logger->info('Auth attempt');

        $entries = $sink->entries();
        self::assertCount(1, $entries);
        self::assertSame('security', $entries[0]->channel);
    }

    #[Test]
    public function loggerDispatchesToMultipleSinks(): void
    {
        $sinkA = new CollectingLogSink();
        $sinkB = new CollectingLogSink();
        $logger = new Logger([$sinkA, $sinkB], LogLevel::Debug, 'multi');

        $logger->info('Broadcast message');

        self::assertCount(1, $sinkA->entries());
        self::assertCount(1, $sinkB->entries());
        self::assertSame('Broadcast message', $sinkA->entries()[0]->message);
        self::assertSame('Broadcast message', $sinkB->entries()[0]->message);
    }

    // ---- Combined observability ----

    #[Test]
    public function metricsAndTracingWorkTogetherThroughMiddlewarePipeline(): void
    {
        $registry = new MetricRegistry();
        $collector = new InMemorySpanCollector();

        $metricsMiddleware = new MetricsMiddleware($registry);
        $tracingMiddleware = new TracingMiddleware($collector, 1.0);

        $request = $this->createRequest(Method::POST, '/api/orders');

        // Tracing wraps metrics wraps handler (same as kernel pipeline order)
        $response = $tracingMiddleware->process(
            $request,
            fn(Request $req): Response => $metricsMiddleware->process(
                $req,
                fn(Request $r): Response => Response::json(['order_id' => 'ORD-001']),
            ),
        );

        self::assertSame(ResponseStatus::OK, $response->status);

        // Verify metrics were recorded
        $counter = $registry->counter('pulsar_http_requests_total');
        $labels = new LabelSet(['method' => 'POST', 'path' => '/api/orders', 'status' => '200']);
        self::assertSame(1.0, $counter->value($labels));

        // Verify tracing captured the span
        self::assertSame(1, $collector->count());
        $span = $collector->spans()[0];
        self::assertSame('HTTP POST /api/orders', $span->name);
        self::assertSame(SpanStatus::Ok, $span->status);

        // Verify traceparent header was propagated
        self::assertNotNull($response->headers->first('traceparent'));
    }
}

/**
 * In-memory log sink that collects entries for test assertions.
 */
class CollectingLogSink implements LogSinkInterface
{
    /** @var list<LogEntry> */
    private array $entries = [];

    public function write(LogEntry $entry): void
    {
        $this->entries[] = $entry;
    }

    /**
     * @return list<LogEntry>
     */
    public function entries(): array
    {
        return $this->entries;
    }
}
