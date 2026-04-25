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
use Pulsar\Http\Middleware\MetricsMiddleware;
use Pulsar\Http\RouteContext;
use Pulsar\Observability\Metrics\LabelSet;
use Pulsar\Observability\Metrics\MetricRegistry;
use RuntimeException;

#[CoversClass(MetricsMiddleware::class)]
final class MetricsMiddlewareTest extends TestCase
{
    private function createRequest(string $path = '/users/42'): ServerRequest
    {
        return new ServerRequest(
            method: 'GET',
            uri: $path,
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
        $handler = new class ($routeContext) implements RequestHandlerInterface {
            public function __construct(private RouteContext $routeContext) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->routeContext->setPattern('/users/{id}');
                $this->routeContext->setName('users.show');

                return Response::text('OK');
            }
        };

        $response = $middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());

        // Verify counter was recorded with route label, not raw path
        $counter = $registry->counter('pulsar_http_requests_total', '');
        $value = $counter->value(new LabelSet(['method' => 'GET', 'route' => 'users.show', 'status' => '200']));
        self::assertSame(1.0, $value);

        // Verify raw path is NOT used as label
        $rawPathValue = $counter->value(new LabelSet(['method' => 'GET', 'route' => '/users/42', 'status' => '200']));
        self::assertSame(0.0, $rawPathValue);
    }

    /**
     * F8.3: when no `RouteContext` is wired the middleware MUST
     * NOT use the raw URI as the metric label — that produces
     * unbounded series (one per dynamic id / uuid path), the
     * Prometheus failure mode #1. The label binds to the bounded
     * sentinel `unmatched` instead.
     */
    #[Test]
    public function fallsBackToUnmatchedSentinelWhenRouteContextIsNull(): void
    {
        $registry = new MetricRegistry();

        $middleware = new MetricsMiddleware($registry, null);

        $request = $this->createRequest('/health');

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::text('OK'));

        $middleware->process($request, $handler);

        $counter = $registry->counter('pulsar_http_requests_total', '');
        $value = $counter->value(new LabelSet(['method' => 'GET', 'route' => 'unmatched', 'status' => '200']));
        self::assertSame(1.0, $value);

        // Raw path MUST NOT be used as label.
        $rawValue = $counter->value(new LabelSet(['method' => 'GET', 'route' => '/health', 'status' => '200']));
        self::assertSame(0.0, $rawValue);
    }

    #[Test]
    public function recordsMetricsEvenOnException(): void
    {
        $registry = new MetricRegistry();
        $routeContext = new RouteContext();

        $middleware = new MetricsMiddleware($registry, $routeContext);

        $request = $this->createRequest('/fail');

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willThrowException(new RuntimeException('handler error'));

        try {
            $middleware->process($request, $handler);
        } catch (RuntimeException) {
            // Expected
        }

        // Metrics should still be recorded with status 500 and 'unmatched' label
        $counter = $registry->counter('pulsar_http_requests_total', '');
        $value = $counter->value(new LabelSet(['method' => 'GET', 'route' => 'unmatched', 'status' => '500']));
        self::assertSame(1.0, $value);
    }

    #[Test]
    public function recordsErrorCounterOn5xxResponse(): void
    {
        $registry = new MetricRegistry();
        $routeContext = new RouteContext();

        $middleware = new MetricsMiddleware($registry, $routeContext);

        $request = $this->createRequest('/error');

        $handler = new class ($routeContext) implements RequestHandlerInterface {
            public function __construct(private RouteContext $routeContext) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->routeContext->setName('error.route');

                return new Response(statusCode: 503);
            }
        };

        $middleware->process($request, $handler);

        // Verify error counter was incremented for 5xx
        $errorCounter = $registry->counter('pulsar_http_errors_total', '');
        $errorValue = $errorCounter->value(new LabelSet(['method' => 'GET', 'route' => 'error.route', 'status' => '503']));
        self::assertSame(1.0, $errorValue);
    }

    #[Test]
    public function doesNotRecordErrorCounterOn4xxResponse(): void
    {
        $registry = new MetricRegistry();
        $routeContext = new RouteContext();

        $middleware = new MetricsMiddleware($registry, $routeContext);

        $request = $this->createRequest('/not-found');

        $handler = new class ($routeContext) implements RequestHandlerInterface {
            public function __construct(private RouteContext $routeContext) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->routeContext->setName('notfound.route');

                return new Response(statusCode: 404);
            }
        };

        $middleware->process($request, $handler);

        // Verify error counter was NOT incremented for 4xx
        $errorCounter = $registry->counter('pulsar_http_errors_total', '');
        $errorValue = $errorCounter->value(new LabelSet(['method' => 'GET', 'route' => 'notfound.route', 'status' => '404']));
        self::assertSame(0.0, $errorValue);

        // But the request counter should still be recorded
        $counter = $registry->counter('pulsar_http_requests_total', '');
        $value = $counter->value(new LabelSet(['method' => 'GET', 'route' => 'notfound.route', 'status' => '404']));
        self::assertSame(1.0, $value);
    }

    #[Test]
    public function recordsDurationHistogram(): void
    {
        $registry = new MetricRegistry();

        $middleware = new MetricsMiddleware($registry, null);

        $request = $this->createRequest('/timed');

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::text('OK'));

        $middleware->process($request, $handler);

        // Verify histogram was recorded — F8.3: when no RouteContext
        // is wired, the label binds to `unmatched`.
        $histogram = $registry->histogram('pulsar_http_request_duration_seconds', '');
        $count = $histogram->count(new LabelSet(['method' => 'GET', 'route' => 'unmatched']));
        self::assertSame(1, $count);
    }

    #[Test]
    public function resetsRouteContextAtStartOfRequest(): void
    {
        $registry = new MetricRegistry();
        $routeContext = new RouteContext();
        $routeContext->setName('stale.route');
        $routeContext->setPattern('/stale/{id}');

        $middleware = new MetricsMiddleware($registry, $routeContext);

        $request = $this->createRequest('/fresh');

        $handler = new class ($routeContext) implements RequestHandlerInterface {
            public function __construct(private RouteContext $routeContext) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                // Verify context was reset before handler runs
                // We set a new route after reset
                $this->routeContext->setName('fresh.route');

                return Response::text('OK');
            }
        };

        $middleware->process($request, $handler);

        $counter = $registry->counter('pulsar_http_requests_total', '');
        $value = $counter->value(new LabelSet(['method' => 'GET', 'route' => 'fresh.route', 'status' => '200']));
        self::assertSame(1.0, $value);
    }

    #[Test]
    public function usesPatternWhenNameIsNull(): void
    {
        $registry = new MetricRegistry();
        $routeContext = new RouteContext();

        $middleware = new MetricsMiddleware($registry, $routeContext);

        $request = $this->createRequest('/users/42');

        $handler = new class ($routeContext) implements RequestHandlerInterface {
            public function __construct(private RouteContext $routeContext) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->routeContext->setPattern('/users/{id}');
                // Name stays null

                return Response::text('OK');
            }
        };

        $middleware->process($request, $handler);

        $counter = $registry->counter('pulsar_http_requests_total', '');
        $value = $counter->value(new LabelSet(['method' => 'GET', 'route' => '/users/{id}', 'status' => '200']));
        self::assertSame(1.0, $value);
    }

    /**
     * F8.18: the metrics scrape endpoint must not auto-monitor itself.
     * If a Prometheus server scrapes `/metrics` every 15s, every scrape
     * would otherwise emit a `pulsar_http_requests_total{route="/metrics"}`
     * sample, dwarfing the real request signal and rebuilding the
     * histogram on every cycle. The middleware short-circuits before
     * recording.
     */
    #[Test]
    public function metricsEndpointIsExcludedFromRecording(): void
    {
        $registry = new MetricRegistry();

        $middleware = new MetricsMiddleware($registry, null);

        $request = $this->createRequest('/metrics');

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::text('# HELP ...'));

        $middleware->process($request, $handler);

        $counter = $registry->counter('pulsar_http_requests_total', '');
        $value = $counter->value(new LabelSet(['method' => 'GET', 'route' => '/metrics', 'status' => '200']));
        self::assertSame(0.0, $value, 'Metrics endpoint must not record itself');
    }

    #[Test]
    public function diagnosticsAndHealthEndpointsAreExcludedFromRecording(): void
    {
        $registry = new MetricRegistry();

        $middleware = new MetricsMiddleware($registry, null);

        foreach (['/_pulsar/metrics', '/_pulsar/diagnostics', '/_pulsar/health'] as $path) {
            $request = $this->createRequest($path);
            $handler = $this->createStub(RequestHandlerInterface::class);
            $handler->method('handle')->willReturn(Response::text('OK'));

            $middleware->process($request, $handler);

            $counter = $registry->counter('pulsar_http_requests_total', '');
            $value = $counter->value(new LabelSet(['method' => 'GET', 'route' => $path, 'status' => '200']));
            self::assertSame(0.0, $value, "Path {$path} must not record");
        }
    }
}
