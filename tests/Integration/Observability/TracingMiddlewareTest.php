<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Observability;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Middleware\TracingMiddleware;
use Pulsar\Http\ResponseStatus;
use Pulsar\Http\TrustedProxy;
use Pulsar\Observability\Tracing\InMemorySpanCollector;
use Pulsar\Observability\Tracing\SpanStatus;
use Pulsar\Observability\Tracing\W3CTraceContextParser;

#[CoversClass(TracingMiddleware::class)]
final class TracingMiddlewareTest extends TestCase
{
    #[Test]
    public function createsRootSpanForRequest(): void
    {
        $collector = new InMemorySpanCollector();
        $middleware = new TracingMiddleware($collector, new W3CTraceContextParser(), samplingRate: 1.0);

        $request = $this->createRequest('GET', '/test');
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::html('ok'));

        $response = $middleware->process($request, $handler);

        self::assertSame(1, $collector->count());

        $span = $collector->spans()[0];
        // Span name is method + route label, not raw path. With no RouteContext
        // wired, the post-route logic falls back to `unmatched` instead of using
        // the raw path, which would make span cardinality unbounded.
        self::assertSame('HTTP GET unmatched', $span->name);
        self::assertSame('/test', $span->attributes()['http.path'] ?? null);
        self::assertSame(SpanStatus::Ok, $span->status);
        self::assertTrue($span->hasEnded());
    }

    #[Test]
    public function propagatesTraceparentHeader(): void
    {
        $collector = new InMemorySpanCollector();
        $middleware = new TracingMiddleware($collector, new W3CTraceContextParser(), samplingRate: 1.0);

        $request = $this->createRequest('GET', '/test');
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::html('ok'));

        $response = $middleware->process($request, $handler);

        $traceparent = $response->getHeaderLine('traceparent');
        self::assertNotSame('', $traceparent);

        $parsed = new W3CTraceContextParser()->parse($traceparent);
        self::assertNotNull($parsed);
        self::assertTrue($parsed->isSampled());
    }

    #[Test]
    public function parsesIncomingTraceparent(): void
    {
        $collector = new InMemorySpanCollector();
        $middleware = new TracingMiddleware(
            $collector,
            new W3CTraceContextParser(),
            samplingRate: 1.0,
            trustedProxy: new TrustedProxy(['10.0.0.1/32']),
        );

        $incomingTraceparent = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01';
        // Propagation is only honoured from the trusted upstream proxy.
        $request = new ServerRequest(
            method: 'GET',
            uri: '/test',
            headers: ['traceparent' => $incomingTraceparent],
            serverParams: ['REMOTE_ADDR' => '10.0.0.1'],
        );

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::html('ok'));

        $response = $middleware->process($request, $handler);

        $span = $collector->spans()[0];
        // Should preserve the parent trace ID
        self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $span->context->traceId->value);
        // Should have the incoming span as parent
        self::assertSame('00f067aa0ba902b7', $span->parentSpanId?->value);
    }

    #[Test]
    public function setsErrorStatusOn5xx(): void
    {
        $collector = new InMemorySpanCollector();
        $middleware = new TracingMiddleware($collector, new W3CTraceContextParser(), samplingRate: 1.0);

        $request = $this->createRequest('POST', '/fail');
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(
            Response::json(['error' => 'fail'], ResponseStatus::InternalServerError->value),
        );

        $response = $middleware->process($request, $handler);

        $span = $collector->spans()[0];
        self::assertSame(SpanStatus::Error, $span->status);
        self::assertSame(500, $span->attributes()['http.status_code']);
    }

    #[Test]
    public function respectsSamplingRate(): void
    {
        $collector = new InMemorySpanCollector();
        $middleware = new TracingMiddleware($collector, new W3CTraceContextParser(), samplingRate: 0.0);

        $request = $this->createRequest('GET', '/test');
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::html('ok'));

        $response = $middleware->process($request, $handler);

        // With 0% sampling, no spans should be collected
        self::assertSame(0, $collector->count());
    }

    /**
     * @param array<string, string> $headers
     */
    private function createRequest(string $method, string $path, array $headers = []): ServerRequest
    {
        return new ServerRequest(
            method: $method,
            uri: $path,
            headers: $headers,
        );
    }
}
