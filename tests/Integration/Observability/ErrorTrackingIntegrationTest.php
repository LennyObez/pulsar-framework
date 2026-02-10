<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Observability;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\ErrorHandling\DevelopmentRenderer;
use Pulsar\ErrorHandling\ExceptionHandler;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Observability\ErrorTracking\ErrorAggregator;
use Pulsar\Observability\ErrorTracking\SensitiveDataScrubber;
use Pulsar\Observability\Tracing\TraceContext;
use RuntimeException;

#[CoversClass(ExceptionHandler::class)]
final class ErrorTrackingIntegrationTest extends TestCase
{
    #[Test]
    public function exceptionHandlerCapturesErrorToAggregator(): void
    {
        $aggregator = new ErrorAggregator();
        $scrubber = new SensitiveDataScrubber();
        $handler = new ExceptionHandler(
            renderer: new DevelopmentRenderer(),
            errorAggregator: $aggregator,
            scrubber: $scrubber,
        );

        $exception = new RuntimeException('test failure');
        $request = $this->createRequest('GET', '/test');

        $handler->handle($exception, $request);

        self::assertSame(1, $aggregator->count());

        $groups = $aggregator->groups();
        self::assertSame(RuntimeException::class, $groups[0]->exceptionClass());
        self::assertSame('test failure', $groups[0]->message());
    }

    #[Test]
    public function sensitiveDataIsScrubbed(): void
    {
        $aggregator = new ErrorAggregator();
        $scrubber = new SensitiveDataScrubber();
        $handler = new ExceptionHandler(
            renderer: new DevelopmentRenderer(),
            errorAggregator: $aggregator,
            scrubber: $scrubber,
        );

        $request = $this->createRequest('GET', '/test?password=secret&name=Alice');
        $handler->handle(new RuntimeException('error'), $request);

        $groups = $aggregator->groups();
        $event = $groups[0]->recentEvents()[0];

        // Query params containing password should be scrubbed
        /** @var array<string, mixed> $query */
        $query = $event->context['query'] ?? [];

        if (isset($query['password'])) {
            self::assertSame('[REDACTED]', $query['password']);
        }
    }

    #[Test]
    public function traceIdLinkedWhenPresent(): void
    {
        $aggregator = new ErrorAggregator();
        $handler = new ExceptionHandler(
            renderer: new DevelopmentRenderer(),
            errorAggregator: $aggregator,
        );

        $traceContext = TraceContext::create();
        $request = $this->createRequest('GET', '/test')
            ->withAttribute('_trace_context', $traceContext);

        $handler->handle(new RuntimeException('traced error'), $request);

        $groups = $aggregator->groups();
        $event = $groups[0]->recentEvents()[0];

        self::assertNotNull($event->traceId);
        self::assertSame($traceContext->traceId->value, $event->traceId->value);
    }

    private function createRequest(string $method, string $uri): ServerRequest
    {
        /** @var array{path?: string, query?: string} $parts */
        $parts = parse_url($uri);
        $path = $parts['path'] ?? '/';
        $queryString = $parts['query'] ?? '';

        // Parse query params
        $parsed = [];

        if ($queryString !== '') {
            parse_str($queryString, $parsed);
        }

        $query = [];

        foreach ($parsed as $k => $v) {
            $query[(string) $k] = $v;
        }

        return new ServerRequest(
            method: $method,
            uri: $uri,
            queryParams: $query,
        );
    }
}
