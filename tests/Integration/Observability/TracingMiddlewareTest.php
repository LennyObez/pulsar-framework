<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Observability;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Method;
use Pulsar\Http\Middleware\TracingMiddleware;
use Pulsar\Http\Request;
use Pulsar\Http\Response;
use Pulsar\Http\ResponseStatus;
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
        $middleware = new TracingMiddleware($collector, samplingRate: 1.0);

        $request = $this->createRequest('GET', '/test');
        $response = $middleware->process($request, static fn() => Response::html('ok'));

        self::assertSame(1, $collector->count());

        $span = $collector->spans()[0];
        self::assertSame('HTTP GET /test', $span->name);
        self::assertSame(SpanStatus::Ok, $span->status);
        self::assertTrue($span->hasEnded());
    }

    #[Test]
    public function propagatesTraceparentHeader(): void
    {
        $collector = new InMemorySpanCollector();
        $middleware = new TracingMiddleware($collector, samplingRate: 1.0);

        $request = $this->createRequest('GET', '/test');
        $response = $middleware->process($request, static fn() => Response::html('ok'));

        $traceparent = $response->headers->first('traceparent');
        self::assertNotNull($traceparent);

        $parsed = W3CTraceContextParser::parse($traceparent);
        self::assertNotNull($parsed);
        self::assertTrue($parsed->isSampled());
    }

    #[Test]
    public function parsesIncomingTraceparent(): void
    {
        $collector = new InMemorySpanCollector();
        $middleware = new TracingMiddleware($collector, samplingRate: 1.0);

        $incomingTraceparent = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01';
        $request = $this->createRequest('GET', '/test', ['traceparent' => [$incomingTraceparent]]);

        $response = $middleware->process($request, static fn() => Response::html('ok'));

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
        $middleware = new TracingMiddleware($collector, samplingRate: 1.0);

        $request = $this->createRequest('POST', '/fail');
        $response = $middleware->process(
            $request,
            static fn() => Response::json(['error' => 'fail'], ResponseStatus::InternalServerError),
        );

        $span = $collector->spans()[0];
        self::assertSame(SpanStatus::Error, $span->status);
        self::assertSame(500, $span->attributes()['http.status_code']);
    }

    #[Test]
    public function respectsSamplingRate(): void
    {
        $collector = new InMemorySpanCollector();
        $middleware = new TracingMiddleware($collector, samplingRate: 0.0);

        $request = $this->createRequest('GET', '/test');
        $response = $middleware->process($request, static fn() => Response::html('ok'));

        // With 0% sampling, no spans should be collected
        self::assertSame(0, $collector->count());
    }

    /**
     * @param array<string, list<string>> $headers
     */
    private function createRequest(string $method, string $path, array $headers = []): Request
    {
        return new Request(
            method: Method::from($method),
            uri: $path,
            path: $path,
            queryString: '',
            headers: new HeaderBag($headers),
            body: '',
        );
    }
}
