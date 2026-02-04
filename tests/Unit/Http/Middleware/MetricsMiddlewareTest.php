<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Method;
use Pulsar\Http\Middleware\MetricsMiddleware;
use Pulsar\Http\Request;
use Pulsar\Http\Response;
use Pulsar\Http\RouteContext;
use Pulsar\Observability\Metrics\LabelSet;
use Pulsar\Observability\Metrics\MetricRegistry;
use RuntimeException;

#[CoversClass(MetricsMiddleware::class)]
final class MetricsMiddlewareTest extends TestCase
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
    public function usesRoutePatternWhenRouteContextIsPopulated(): void
    {
        $registry = new MetricRegistry();
        $routeContext = new RouteContext();

        $middleware = new MetricsMiddleware($registry, $routeContext);

        $request = $this->createRequest('/users/42');

        // Simulate Kernel populating RouteContext after matching
        $response = $middleware->process($request, static function () use ($routeContext): Response {
            $routeContext->pattern = '/users/{id}';
            $routeContext->name = 'users.show';

            return Response::text('OK');
        });

        self::assertSame(200, $response->status->value);

        // Verify counter was recorded with route label, not raw path
        $counter = $registry->counter('pulsar_http_requests_total', '');
        $value = $counter->value(new LabelSet(['method' => 'GET', 'route' => 'users.show', 'status' => '200']));
        self::assertSame(1.0, $value);

        // Verify raw path is NOT used as label
        $rawPathValue = $counter->value(new LabelSet(['method' => 'GET', 'route' => '/users/42', 'status' => '200']));
        self::assertSame(0.0, $rawPathValue);
    }

    #[Test]
    public function fallsBackToRawPathWhenRouteContextIsNull(): void
    {
        $registry = new MetricRegistry();

        $middleware = new MetricsMiddleware($registry, null);

        $request = $this->createRequest('/health');

        $middleware->process($request, static fn(): Response => Response::text('OK'));

        $counter = $registry->counter('pulsar_http_requests_total', '');
        $value = $counter->value(new LabelSet(['method' => 'GET', 'route' => '/health', 'status' => '200']));
        self::assertSame(1.0, $value);
    }

    #[Test]
    public function recordsMetricsEvenOnException(): void
    {
        $registry = new MetricRegistry();
        $routeContext = new RouteContext();

        $middleware = new MetricsMiddleware($registry, $routeContext);

        $request = $this->createRequest('/fail');

        try {
            $middleware->process($request, static function (): never {
                throw new RuntimeException('handler error');
            });
        } catch (RuntimeException) {
            // Expected
        }

        // Metrics should still be recorded with status 500 and 'unmatched' label
        $counter = $registry->counter('pulsar_http_requests_total', '');
        $value = $counter->value(new LabelSet(['method' => 'GET', 'route' => 'unmatched', 'status' => '500']));
        self::assertSame(1.0, $value);
    }
}
