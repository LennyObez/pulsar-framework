<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Middleware\TracingMiddleware;
use Pulsar\Http\RouteContext;
use Pulsar\Http\TrustedProxy;
use Pulsar\Observability\Tracing\InMemorySpanCollector;
use Pulsar\Observability\Tracing\W3CTraceContextParser;

#[CoversClass(TracingMiddleware::class)]
final class TracingMiddlewareTest extends TestCase
{
    private function createRequest(string $path = '/users/42'): ServerRequest
    {
        return new ServerRequest(
            method: 'GET',
            uri: $path,
        );
    }

    #[Test]
    public function spanNameUsesRoutePatternFromContext(): void
    {
        $collector = new InMemorySpanCollector();
        $routeContext = new RouteContext();

        $middleware = new TracingMiddleware(
            collector: $collector,
            traceContextParser: new W3CTraceContextParser(),
            routeContext: $routeContext,
        );

        $request = $this->createRequest('/users/42');

        $handler = new class ($routeContext) implements RequestHandlerInterface {
            public function __construct(private RouteContext $routeContext) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->routeContext->setPattern('/users/{id}');
                $this->routeContext->setName('users.show');

                return Response::text('OK');
            }
        };

        $middleware->process($request, $handler);

        $spans = $collector->spans();
        self::assertCount(1, $spans);

        $span = $spans[0];
        self::assertSame('HTTP GET users.show', $span->name);
        self::assertSame('users.show', $span->attributes()['http.route'] ?? null);
    }

    /**
     * F24.6: span name uses `unmatched` rather than the raw path
     * when no RouteContext is wired. Keeping the raw path made
     * span cardinality unbounded for any request that threw before
     * route matching (parse errors, pre-router middleware throws).
     * The raw path is still available on `http.path` attribute.
     */
    #[Test]
    public function spanNameUsesUnmatchedWhenRouteContextIsNull(): void
    {
        $collector = new InMemorySpanCollector();

        $middleware = new TracingMiddleware(
            collector: $collector,
            traceContextParser: new W3CTraceContextParser(),
            routeContext: null,
        );

        $request = $this->createRequest('/users/42');

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::text('OK'));

        $middleware->process($request, $handler);

        $spans = $collector->spans();
        self::assertCount(1, $spans);

        $span = $spans[0];
        self::assertSame('HTTP GET unmatched', $span->name);
        self::assertSame('/users/42', $span->attributes()['http.path'] ?? null);
    }

    #[Test]
    public function spanNameUsesUnmatchedLabelWhenRouteContextIsUnpopulated(): void
    {
        $collector = new InMemorySpanCollector();
        $routeContext = new RouteContext();

        $middleware = new TracingMiddleware(
            collector: $collector,
            traceContextParser: new W3CTraceContextParser(),
            routeContext: $routeContext,
        );

        $request = $this->createRequest('/not-found');

        // RouteContext is never populated (simulating unmatched route)
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::text('Not Found'));

        $middleware->process($request, $handler);

        $spans = $collector->spans();
        self::assertCount(1, $spans);

        $span = $spans[0];
        self::assertSame('HTTP GET unmatched', $span->name);
        self::assertSame('/not-found', $span->attributes()['http.path'] ?? null);
    }

    private const string INBOUND_TRACE_ID = '4bf92f3577b34da6a3ce929d0e0e4736';

    private const string INBOUND_TRACEPARENT = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01';

    #[Test]
    public function inboundTraceparentIsIgnoredWhenNoTrustedProxyIsConfigured(): void
    {
        // Arrange — no TrustedProxy wired (deny-by-default): a direct client
        // sends a forged traceparent with sampled=01
        $collector = new InMemorySpanCollector();
        $middleware = new TracingMiddleware(
            collector: $collector,
            traceContextParser: new W3CTraceContextParser(),
        );

        $request = new ServerRequest(
            method: 'GET',
            uri: '/orders',
            headers: ['traceparent' => self::INBOUND_TRACEPARENT],
            serverParams: ['REMOTE_ADDR' => '203.0.113.9'],
        );
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::text('OK'));

        // Act
        $middleware->process($request, $handler);

        // Assert — a fresh ROOT span: the client-supplied trace id is not
        // adopted and no parent span is recorded
        $spans = $collector->spans();
        self::assertCount(1, $spans);
        self::assertNotSame(self::INBOUND_TRACE_ID, $spans[0]->context->traceId->toString());
        self::assertNull($spans[0]->parentSpanId);
    }

    #[Test]
    public function inboundTraceparentIsHonouredFromATrustedSource(): void
    {
        // Arrange — the request arrives FROM the declared trusted proxy
        $collector = new InMemorySpanCollector();
        $middleware = new TracingMiddleware(
            collector: $collector,
            traceContextParser: new W3CTraceContextParser(),
            trustedProxy: new TrustedProxy(['10.0.0.1/32']),
        );

        $request = new ServerRequest(
            method: 'GET',
            uri: '/orders',
            headers: ['traceparent' => self::INBOUND_TRACEPARENT],
            serverParams: ['REMOTE_ADDR' => '10.0.0.1'],
        );
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::text('OK'));

        // Act
        $middleware->process($request, $handler);

        // Assert — distributed-trace continuity: the span continues the
        // inbound trace as a CHILD of the upstream span
        $spans = $collector->spans();
        self::assertCount(1, $spans);
        self::assertSame(self::INBOUND_TRACE_ID, $spans[0]->context->traceId->toString());
        self::assertNotNull($spans[0]->parentSpanId);
    }

    #[Test]
    public function inboundTraceparentIsIgnoredFromAnUntrustedSource(): void
    {
        // Arrange — a TrustedProxy IS configured, but the request comes from
        // an address outside the trusted chain
        $collector = new InMemorySpanCollector();
        $middleware = new TracingMiddleware(
            collector: $collector,
            traceContextParser: new W3CTraceContextParser(),
            trustedProxy: new TrustedProxy(['10.0.0.1/32']),
        );

        $request = new ServerRequest(
            method: 'GET',
            uri: '/orders',
            headers: ['traceparent' => self::INBOUND_TRACEPARENT],
            serverParams: ['REMOTE_ADDR' => '203.0.113.9'],
        );
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::text('OK'));

        // Act
        $middleware->process($request, $handler);

        // Assert — fresh root span, forged topology rejected
        $spans = $collector->spans();
        self::assertCount(1, $spans);
        self::assertNotSame(self::INBOUND_TRACE_ID, $spans[0]->context->traceId->toString());
        self::assertNull($spans[0]->parentSpanId);
    }
}
