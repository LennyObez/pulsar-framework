<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Middleware\TracingMiddleware;
use Pulsar\Http\RouteContext;
use Pulsar\Observability\Tracing\InMemorySpanCollector;
use Pulsar\Observability\Tracing\SpanStatus;
use Pulsar\Observability\Tracing\W3CTraceContextParser;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;
use Throwable;

#[CoversClass(TracingMiddleware::class)]
final class TracingMiddlewareCoverageTest extends TestCase
{
    private InMemorySpanCollector $collector;
    private W3CTraceContextParser $parser;

    protected function setUp(): void
    {
        $this->collector = new InMemorySpanCollector();
        $this->parser = new W3CTraceContextParser();
    }

    #[Test]
    public function zeroSamplingRateSkipsTracing(): void
    {
        $middleware = new TracingMiddleware(
            collector: $this->collector,
            traceContextParser: $this->parser,
            samplingRate: 0.0,
        );

        $request = new ServerRequest(method: 'GET', uri: '/health');
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::text('OK'));

        $response = $middleware->process($request, $handler);

        self::assertSame('OK', (string) $response->getBody());
        self::assertCount(0, $this->collector->spans());
        self::assertSame('', $response->getHeaderLine('traceparent'));
    }

    #[Test]
    public function fullSamplingRateAlwaysTraces(): void
    {
        $middleware = new TracingMiddleware(
            collector: $this->collector,
            traceContextParser: $this->parser,
            samplingRate: 1.0,
        );

        $request = new ServerRequest(method: 'POST', uri: '/api/users');
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response(201));

        $response = $middleware->process($request, $handler);

        self::assertCount(1, $this->collector->spans());
        self::assertNotEmpty($response->getHeaderLine('traceparent'));
    }

    #[Test]
    public function partialSamplingRateUsesRandomizer(): void
    {
        // Use a seeded randomizer so the test is deterministic
        // Mt19937 with seed 42: getInt(0,999) returns a predictable value
        $randomizer = new Randomizer(new Mt19937(42));

        $middleware = new TracingMiddleware(
            collector: $this->collector,
            traceContextParser: $this->parser,
            samplingRate: 0.5,
            randomizer: $randomizer,
        );

        $request = new ServerRequest(method: 'GET', uri: '/test');
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::text('OK'));

        // Run multiple times - with 50% rate some should be sampled, some not
        $sampled = 0;
        for ($i = 0; $i < 10; $i++) {
            $this->collector->clear();
            $mw = new TracingMiddleware(
                collector: $this->collector,
                traceContextParser: $this->parser,
                samplingRate: 0.5,
                randomizer: new Randomizer(new Mt19937($i)),
            );
            $mw->process($request, $handler);
            $sampled += $this->collector->count();
        }

        // With 50% rate over 10 attempts, should have some sampled and some not
        self::assertGreaterThan(0, $sampled);
        self::assertLessThan(10, $sampled);
    }

    #[Test]
    public function sampledParentContextIsAlwaysSampled(): void
    {
        $middleware = new TracingMiddleware(
            collector: $this->collector,
            traceContextParser: $this->parser,
            samplingRate: 0.0, // Would normally skip
        );

        // traceparent with sampled flag (01)
        $request = new ServerRequest(
            method: 'GET',
            uri: '/api',
            headers: ['traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01'],
        );

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::text('OK'));

        $response = $middleware->process($request, $handler);

        // Even with 0% sampling, parent sampled flag causes sampling
        self::assertCount(1, $this->collector->spans());
        self::assertNotEmpty($response->getHeaderLine('traceparent'));
    }

    #[Test]
    public function unsampledParentContextIsNotSampled(): void
    {
        $middleware = new TracingMiddleware(
            collector: $this->collector,
            traceContextParser: $this->parser,
            samplingRate: 1.0, // Would normally sample
        );

        // traceparent with unsampled flag (00)
        $request = new ServerRequest(
            method: 'GET',
            uri: '/api',
            headers: ['traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-00'],
        );

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::text('OK'));

        $response = $middleware->process($request, $handler);

        // Even with 100% sampling, unsampled parent flag prevents sampling
        self::assertCount(0, $this->collector->spans());
    }

    #[Test]
    public function spanStatusIsErrorFor5xx(): void
    {
        $middleware = new TracingMiddleware(
            collector: $this->collector,
            traceContextParser: $this->parser,
        );

        $request = new ServerRequest(method: 'GET', uri: '/fail');
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response(500));

        $middleware->process($request, $handler);

        $span = $this->collector->spans()[0];
        self::assertSame(SpanStatus::Error, $span->status);
        self::assertSame(500, $span->attributes()['http.status_code']);
    }

    #[Test]
    public function spanStatusIsUnsetFor4xx(): void
    {
        $middleware = new TracingMiddleware(
            collector: $this->collector,
            traceContextParser: $this->parser,
        );

        $request = new ServerRequest(method: 'GET', uri: '/not-found');
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response(404));

        $middleware->process($request, $handler);

        $span = $this->collector->spans()[0];
        self::assertSame(SpanStatus::Unset, $span->status);
    }

    #[Test]
    public function spanStatusIsOkFor2xx(): void
    {
        $middleware = new TracingMiddleware(
            collector: $this->collector,
            traceContextParser: $this->parser,
        );

        $request = new ServerRequest(method: 'GET', uri: '/ok');
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response(200));

        $middleware->process($request, $handler);

        $span = $this->collector->spans()[0];
        self::assertSame(SpanStatus::Ok, $span->status);
    }

    #[Test]
    public function spanSetsHttpAttributes(): void
    {
        $middleware = new TracingMiddleware(
            collector: $this->collector,
            traceContextParser: $this->parser,
        );

        $request = new ServerRequest(method: 'POST', uri: 'http://example.com/api/items?q=test');
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response(201));

        $middleware->process($request, $handler);

        $attrs = $this->collector->spans()[0]->attributes();
        self::assertSame('POST', $attrs['http.method']);
        self::assertSame('/api/items', $attrs['http.path']);
        self::assertSame('http://example.com/api/items?q=test', $attrs['http.url']);
        self::assertSame(201, $attrs['http.status_code']);
    }

    #[Test]
    public function traceparentHeaderPropagatesChildContext(): void
    {
        $middleware = new TracingMiddleware(
            collector: $this->collector,
            traceContextParser: $this->parser,
        );

        $parentTraceparent = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01';
        $request = new ServerRequest(
            method: 'GET',
            uri: '/api',
            headers: ['traceparent' => $parentTraceparent],
        );

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::text('OK'));

        $response = $middleware->process($request, $handler);

        $responseTraceparent = $response->getHeaderLine('traceparent');
        self::assertNotEmpty($responseTraceparent);
        // Child should preserve trace ID but have new span ID
        self::assertStringStartsWith('00-4bf92f3577b34da6a3ce929d0e0e4736-', $responseTraceparent);
        self::assertNotSame($parentTraceparent, $responseTraceparent);
    }

    #[Test]
    public function spanEndIsCalledEvenOnException(): void
    {
        $middleware = new TracingMiddleware(
            collector: $this->collector,
            traceContextParser: $this->parser,
        );

        $request = new ServerRequest(method: 'GET', uri: '/error');
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willThrowException(new RuntimeException('boom'));

        try {
            $middleware->process($request, $handler);
        } catch (Throwable) {
            // Expected — stub throws RuntimeException
        }

        // Span should still be ended and collected via finally block
        self::assertCount(1, $this->collector->spans());
        self::assertTrue($this->collector->spans()[0]->hasEnded());
    }

    #[Test]
    public function routeContextWithPatternButNoNameUsesPattern(): void
    {
        $routeContext = new RouteContext();

        $middleware = new TracingMiddleware(
            collector: $this->collector,
            traceContextParser: $this->parser,
            routeContext: $routeContext,
        );

        $request = new ServerRequest(method: 'GET', uri: '/items/5');

        $handler = new class ($routeContext) implements RequestHandlerInterface {
            public function __construct(private RouteContext $rc) {}

            public function handle(\Psr\Http\Message\ServerRequestInterface $request): ResponseInterface
            {
                $this->rc->setPattern('/items/{id}');
                // name stays null - label() returns pattern

                return Response::text('OK');
            }
        };

        $middleware->process($request, $handler);

        $span = $this->collector->spans()[0];
        self::assertSame('HTTP GET /items/{id}', $span->name);
        self::assertSame('/items/{id}', $span->attributes()['http.route']);
    }

    #[Test]
    public function invalidTraceparentIsIgnored(): void
    {
        $middleware = new TracingMiddleware(
            collector: $this->collector,
            traceContextParser: $this->parser,
        );

        $request = new ServerRequest(
            method: 'GET',
            uri: '/api',
            headers: ['traceparent' => 'invalid-header'],
        );

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::text('OK'));

        $response = $middleware->process($request, $handler);

        // Should still trace (no parent = new root, 100% sampling)
        self::assertCount(1, $this->collector->spans());
        self::assertNotEmpty($response->getHeaderLine('traceparent'));
    }
}
