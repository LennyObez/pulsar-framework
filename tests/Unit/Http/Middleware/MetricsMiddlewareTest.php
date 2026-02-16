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
                $this->routeContext->pattern = '/users/{id}';
                $this->routeContext->name = 'users.show';

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

    #[Test]
    public function fallsBackToRawPathWhenRouteContextIsNull(): void
    {
        $registry = new MetricRegistry();

        $middleware = new MetricsMiddleware($registry, null);

        $request = $this->createRequest('/health');

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::text('OK'));

        $middleware->process($request, $handler);

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
}
