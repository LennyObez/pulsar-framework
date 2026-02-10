<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Observability;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Middleware\MetricsMiddleware;
use Pulsar\Http\ResponseStatus;
use Pulsar\Observability\Metrics\LabelSet;
use Pulsar\Observability\Metrics\MetricRegistry;

#[CoversClass(MetricsMiddleware::class)]
final class MetricsMiddlewareTest extends TestCase
{
    #[Test]
    public function recordsRequestCounter(): void
    {
        $registry = new MetricRegistry();
        $middleware = new MetricsMiddleware($registry);

        $request = $this->createRequest('GET', '/users');
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::html('ok'));

        $middleware->process($request, $handler);

        $counter = $registry->counter('pulsar_http_requests_total');
        $labels = new LabelSet(['method' => 'GET', 'route' => '/users', 'status' => '200']);

        self::assertSame(1.0, $counter->value($labels));
    }

    #[Test]
    public function recordsDurationHistogram(): void
    {
        $registry = new MetricRegistry();
        $middleware = new MetricsMiddleware($registry);

        $request = $this->createRequest('POST', '/api');
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::json(['ok' => true]));

        $middleware->process($request, $handler);

        $histogram = $registry->histogram('pulsar_http_request_duration_seconds');
        $labels = new LabelSet(['method' => 'POST', 'route' => '/api']);

        self::assertSame(1, $histogram->count($labels));
        self::assertGreaterThan(0.0, $histogram->sum($labels));
    }

    #[Test]
    public function recordsErrorCounterOn5xx(): void
    {
        $registry = new MetricRegistry();
        $middleware = new MetricsMiddleware($registry);

        $request = $this->createRequest('GET', '/fail');
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(
            Response::json(['error' => 'fail'], ResponseStatus::InternalServerError->value),
        );

        $middleware->process($request, $handler);

        $errorCounter = $registry->counter('pulsar_http_errors_total');
        $labels = new LabelSet(['method' => 'GET', 'route' => '/fail', 'status' => '500']);

        self::assertSame(1.0, $errorCounter->value($labels));
    }

    #[Test]
    public function doesNotRecordErrorCounterOnSuccess(): void
    {
        $registry = new MetricRegistry();
        $middleware = new MetricsMiddleware($registry);

        $request = $this->createRequest('GET', '/ok');
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::html('ok'));

        $middleware->process($request, $handler);

        // Error counter should not have been incremented
        $errorCounter = $registry->counter('pulsar_http_errors_total');
        self::assertSame(0.0, $errorCounter->value());
    }

    private function createRequest(string $method, string $path): ServerRequest
    {
        return new ServerRequest(
            method: $method,
            uri: $path,
        );
    }
}
