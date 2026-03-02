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

    #[Test]
    public function spanNameKeepsRawPathWhenRouteContextIsNull(): void
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
        self::assertSame('HTTP GET /users/42', $span->name);
    }

    #[Test]
    public function spanNameKeepsRawPathWhenRouteContextIsUnmatched(): void
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
        // 'unmatched' label should NOT replace the raw path for unmatched routes
        self::assertSame('HTTP GET /not-found', $span->name);
    }
}
