<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Method;
use Pulsar\Http\Middleware\TracingMiddleware;
use Pulsar\Http\Request;
use Pulsar\Http\Response;
use Pulsar\Http\RouteContext;
use Pulsar\Observability\Tracing\InMemorySpanCollector;

#[CoversClass(TracingMiddleware::class)]
final class TracingMiddlewareTest extends TestCase
{
    private function createRequest(string $path = '/users/42'): Request
    {
        return new Request(
            method: Method::GET,
            uri: $path,
            path: $path,
            queryString: '',
            headers: new HeaderBag([]),
            body: '',
        );
    }

    #[Test]
    public function spanNameUsesRoutePatternFromContext(): void
    {
        $collector = new InMemorySpanCollector();
        $routeContext = new RouteContext();

        $middleware = new TracingMiddleware(
            collector: $collector,
            routeContext: $routeContext,
        );

        $request = $this->createRequest('/users/42');

        $middleware->process($request, static function () use ($routeContext): Response {
            $routeContext->pattern = '/users/{id}';
            $routeContext->name = 'users.show';

            return Response::text('OK');
        });

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
            routeContext: null,
        );

        $request = $this->createRequest('/users/42');

        $middleware->process($request, static fn(): Response => Response::text('OK'));

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
            routeContext: $routeContext,
        );

        $request = $this->createRequest('/not-found');

        // RouteContext is never populated (simulating unmatched route)
        $middleware->process($request, static fn(): Response => Response::text('Not Found'));

        $spans = $collector->spans();
        self::assertCount(1, $spans);

        $span = $spans[0];
        // 'unmatched' label should NOT replace the raw path for unmatched routes
        self::assertSame('HTTP GET /not-found', $span->name);
    }
}
