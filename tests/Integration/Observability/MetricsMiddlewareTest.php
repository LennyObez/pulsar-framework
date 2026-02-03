<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Observability;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Method;
use Pulsar\Http\Middleware\MetricsMiddleware;
use Pulsar\Http\Request;
use Pulsar\Http\Response;
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
        $middleware->process($request, static fn() => Response::html('ok'));

        $counter = $registry->counter('pulsar_http_requests_total');
        $labels = new LabelSet(['method' => 'GET', 'path' => '/users', 'status' => '200']);

        self::assertSame(1.0, $counter->value($labels));
    }

    #[Test]
    public function recordsDurationHistogram(): void
    {
        $registry = new MetricRegistry();
        $middleware = new MetricsMiddleware($registry);

        $request = $this->createRequest('POST', '/api');
        $middleware->process($request, static fn() => Response::json(['ok' => true]));

        $histogram = $registry->histogram('pulsar_http_request_duration_seconds');
        $labels = new LabelSet(['method' => 'POST', 'path' => '/api']);

        self::assertSame(1, $histogram->count($labels));
        self::assertGreaterThan(0.0, $histogram->sum($labels));
    }

    #[Test]
    public function recordsErrorCounterOn5xx(): void
    {
        $registry = new MetricRegistry();
        $middleware = new MetricsMiddleware($registry);

        $request = $this->createRequest('GET', '/fail');
        $middleware->process(
            $request,
            static fn() => Response::json(['error' => 'fail'], ResponseStatus::InternalServerError),
        );

        $errorCounter = $registry->counter('pulsar_http_errors_total');
        $labels = new LabelSet(['method' => 'GET', 'path' => '/fail', 'status' => '500']);

        self::assertSame(1.0, $errorCounter->value($labels));
    }

    #[Test]
    public function doesNotRecordErrorCounterOnSuccess(): void
    {
        $registry = new MetricRegistry();
        $middleware = new MetricsMiddleware($registry);

        $request = $this->createRequest('GET', '/ok');
        $middleware->process($request, static fn() => Response::html('ok'));

        // Error counter should not have been incremented
        $errorCounter = $registry->counter('pulsar_http_errors_total');
        self::assertSame(0.0, $errorCounter->value());
    }

    private function createRequest(string $method, string $path): Request
    {
        return new Request(
            method: Method::from($method),
            uri: $path,
            path: $path,
            queryString: '',
            headers: new HeaderBag([]),
            body: '',
        );
    }
}
