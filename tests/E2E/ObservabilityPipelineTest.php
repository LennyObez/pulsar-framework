<?php

declare(strict_types=1);

namespace Pulsar\Tests\E2E;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Middleware\MetricsMiddleware;
use Pulsar\Http\Middleware\TracingMiddleware;
use Pulsar\Http\ResponseStatus;
use Pulsar\Http\TrustedProxy;
use Pulsar\Observability\Log\LogEntry;
use Pulsar\Observability\Log\Logger;
use Pulsar\Observability\Log\LogLevel;
use Pulsar\Observability\Log\LogSinkInterface;
use Pulsar\Observability\Metrics\LabelSet;
use Pulsar\Observability\Metrics\MetricRegistry;
use Pulsar\Observability\Tracing\InMemorySpanCollector;
use Pulsar\Observability\Tracing\Span;
use Pulsar\Observability\Tracing\SpanStatus;
use Pulsar\Observability\Tracing\TraceContext;
use Pulsar\Observability\Tracing\W3CTraceContextParser;

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
    /**
     * @param array<string, string|list<string>> $headers
     */
    private function createRequest(
        string $method = 'GET',
        string $path = '/',
        array $headers = [],
    ): ServerRequest {
        return new ServerRequest(
            method: $method,
            uri: $path,
            headers: $headers,
        );
    }

    private function createHandler(callable $fn): RequestHandlerInterface
    {
        return new class ($fn) implements RequestHandlerInterface {
            /** @param callable(ServerRequestInterface): ResponseInterface $fn */
            public function __construct(private readonly mixed $fn) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return ($this->fn)($request);
            }
        };
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

        $request = $this->createRequest('GET', '/api/users');

        $response = $middleware->process(
            $request,
            $this->createHandler(fn(ServerRequestInterface $req): ResponseInterface => Response::json(['users' => []])),
        );

        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());

        // Verify request counter was incremented
        self::assertTrue($registry->has('pulsar_http_requests_total'));
        $counter = $registry->counter('pulsar_http_requests_total');
        $labels = new LabelSet(['method' => 'GET', 'route' => 'unmatched', 'status' => '200']);
        self::assertSame(1.0, $counter->value($labels));

        // Verify duration histogram was recorded
        self::assertTrue($registry->has('pulsar_http_request_duration_seconds'));
        $histogram = $registry->histogram('pulsar_http_request_duration_seconds');
        $durationLabels = new LabelSet(['method' => 'GET', 'route' => 'unmatched']);
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
                $this->createRequest('GET', '/health'),
                $this->createHandler(fn(ServerRequestInterface $req): ResponseInterface => Response::text('ok')),
            );
        }

        $counter = $registry->counter('pulsar_http_requests_total');
        $labels = new LabelSet(['method' => 'GET', 'route' => 'unmatched', 'status' => '200']);
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
        $middleware = new TracingMiddleware($collector, new W3CTraceContextParser(), 1.0);

        $request = $this->createRequest('GET', '/api/items');

        $response = $middleware->process(
            $request,
            $this->createHandler(fn(ServerRequestInterface $req): ResponseInterface => Response::text('traced')),
        );

        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
        self::assertSame('traced', (string) $response->getBody());

        // Verify span was collected
        self::assertSame(1, $collector->count());
        $spans = $collector->spans();
        $span = $spans[0];

        // Span name is `HTTP <method> unmatched` when no RouteContext is wired
        // (this E2E pipeline does not exercise the router). The raw path is never
        // used as the name: cardinality would be unbounded for any request that
        // throws pre-routing.
        self::assertSame('HTTP GET unmatched', $span->name);
        self::assertSame('/api/items', $span->attributes()['http.path'] ?? null);
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
        $middleware = new TracingMiddleware($collector, new W3CTraceContextParser(), 1.0);

        $response = $middleware->process(
            $this->createRequest(),
            $this->createHandler(fn(ServerRequestInterface $req): ResponseInterface => Response::text('ok')),
        );

        $traceparent = $response->getHeaderLine('traceparent');
        self::assertNotEmpty($traceparent);
        self::assertMatchesRegularExpression(
            '/^00-[a-f0-9]{32}-[a-f0-9]{16}-[a-f0-9]{2}$/',
            $traceparent,
        );
    }

    #[Test]
    public function tracingMiddlewarePropagatesIncomingTraceContext(): void
    {
        $collector = new InMemorySpanCollector();
        $middleware = new TracingMiddleware(
            $collector,
            new W3CTraceContextParser(),
            1.0,
            trustedProxy: new TrustedProxy(['10.0.0.1/32']),
        );

        $incomingTraceparent = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01';
        // Propagation is only honoured from the trusted upstream proxy.
        $request = new ServerRequest(
            method: 'GET',
            uri: '/',
            headers: ['traceparent' => $incomingTraceparent],
            serverParams: ['REMOTE_ADDR' => '10.0.0.1'],
        );

        $response = $middleware->process(
            $request,
            $this->createHandler(fn(ServerRequestInterface $req): ResponseInterface => Response::text('propagated')),
        );

        // Verify the span uses the incoming trace ID
        $spans = $collector->spans();
        self::assertCount(1, $spans);

        $span = $spans[0];
        self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $span->context->traceId->value);

        // Response should contain traceparent with same trace ID
        $responseTraceparent = $response->getHeaderLine('traceparent');
        self::assertNotEmpty($responseTraceparent);
        self::assertStringContainsString('4bf92f3577b34da6a3ce929d0e0e4736', $responseTraceparent);
    }

    #[Test]
    public function tracingMiddlewareAttachesContextToRequestAttributes(): void
    {
        $collector = new InMemorySpanCollector();
        $middleware = new TracingMiddleware($collector, new W3CTraceContextParser(), 1.0);

        $capturedContext = null;
        $capturedSpan = null;

        $middleware->process(
            $this->createRequest(),
            $this->createHandler(function (ServerRequestInterface $req) use (&$capturedContext, &$capturedSpan): ResponseInterface {
                $capturedContext = $req->getAttribute('_trace_context');
                $capturedSpan = $req->getAttribute('_root_span');
                return Response::text('ok');
            }),
        );

        self::assertInstanceOf(TraceContext::class, $capturedContext);
        self::assertInstanceOf(Span::class, $capturedSpan);
    }

    #[Test]
    public function tracingMiddlewareSetsErrorStatusOnServerError(): void
    {
        $collector = new InMemorySpanCollector();
        $middleware = new TracingMiddleware($collector, new W3CTraceContextParser(), 1.0);

        $middleware->process(
            $this->createRequest(),
            $this->createHandler(fn(ServerRequestInterface $req): ResponseInterface => new Response(
                statusCode: ResponseStatus::InternalServerError->value,
                body: 'Server Error',
            )),
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
    public function loggingAndTracingShareNoStateAcrossIndependentRequests(): void
    {
        $collector1 = new InMemorySpanCollector();
        $middleware1 = new TracingMiddleware($collector1, new W3CTraceContextParser(), 1.0);

        $collector2 = new InMemorySpanCollector();
        $middleware2 = new TracingMiddleware($collector2, new W3CTraceContextParser(), 1.0);

        // Process through two independent middleware instances
        $middleware1->process(
            $this->createRequest('GET', '/req-one'),
            $this->createHandler(fn(ServerRequestInterface $req): ResponseInterface => Response::text('one')),
        );

        $middleware2->process(
            $this->createRequest('GET', '/req-two'),
            $this->createHandler(fn(ServerRequestInterface $req): ResponseInterface => Response::text('two')),
        );

        // Each collector must hold exactly its own span, not the other's
        self::assertSame(1, $collector1->count());
        self::assertSame(1, $collector2->count());
        // Span name is `HTTP <method> unmatched` when no RouteContext is wired.
        // The raw path lives in the `http.path` attribute instead.
        self::assertSame('HTTP GET unmatched', $collector1->spans()[0]->name);
        self::assertSame('HTTP GET unmatched', $collector2->spans()[0]->name);
        self::assertSame('/req-one', $collector1->spans()[0]->attributes()['http.path'] ?? null);
        self::assertSame('/req-two', $collector2->spans()[0]->attributes()['http.path'] ?? null);
    }

    #[Test]
    public function tracingSpanHasUniqueIdsAcrossMultipleRequests(): void
    {
        $collector = new InMemorySpanCollector();
        $middleware = new TracingMiddleware($collector, new W3CTraceContextParser(), 1.0);

        $middleware->process(
            $this->createRequest('GET', '/first'),
            $this->createHandler(fn(ServerRequestInterface $req): ResponseInterface => Response::text('first')),
        );

        $middleware->process(
            $this->createRequest('GET', '/second'),
            $this->createHandler(fn(ServerRequestInterface $req): ResponseInterface => Response::text('second')),
        );

        self::assertSame(2, $collector->count());
        $spans = $collector->spans();

        // Each request generates a distinct trace ID and span ID
        self::assertNotSame(
            $spans[0]->context->traceId->value,
            $spans[1]->context->traceId->value,
            'Independent requests must have different trace IDs',
        );

        self::assertNotSame(
            $spans[0]->context->spanId->value,
            $spans[1]->context->spanId->value,
            'Independent requests must have different span IDs',
        );
    }

    #[Test]
    public function metricsAndTracingWorkTogetherThroughMiddlewarePipeline(): void
    {
        $registry = new MetricRegistry();
        $collector = new InMemorySpanCollector();

        $metricsMiddleware = new MetricsMiddleware($registry);
        $tracingMiddleware = new TracingMiddleware($collector, new W3CTraceContextParser(), 1.0);

        $request = $this->createRequest('POST', '/api/orders');

        $innerHandler = $this->createHandler(
            fn(ServerRequestInterface $r): ResponseInterface => Response::json(['order_id' => 'ORD-001']),
        );

        $metricsHandler = new class ($metricsMiddleware, $innerHandler) implements RequestHandlerInterface {
            public function __construct(
                private readonly MetricsMiddleware $middleware,
                private readonly RequestHandlerInterface $inner,
            ) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->middleware->process($request, $this->inner);
            }
        };

        // Tracing wraps metrics wraps handler (same as kernel pipeline order)
        $response = $tracingMiddleware->process($request, $metricsHandler);

        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());

        // With no RouteContext wired the label binds to the bounded sentinel
        // `unmatched`, which prevents unbounded label cardinality from
        // dynamic-id paths.
        $counter = $registry->counter('pulsar_http_requests_total');
        $labels = new LabelSet(['method' => 'POST', 'route' => 'unmatched', 'status' => '200']);
        self::assertSame(1.0, $counter->value($labels));

        // The span name uses the route label when RouteContext is wired and falls
        // back to `unmatched` when not — this E2E pipeline has no router.
        self::assertSame(1, $collector->count());
        $span = $collector->spans()[0];
        self::assertSame('HTTP POST unmatched', $span->name);
        self::assertSame('/api/orders', $span->attributes()['http.path'] ?? null);
        self::assertSame(SpanStatus::Ok, $span->status);

        // Verify traceparent header was propagated
        self::assertNotEmpty($response->getHeaderLine('traceparent'));
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
