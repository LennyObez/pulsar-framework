<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\ErrorHandling;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Context\CausationId;
use Pulsar\Context\CorrelationId;
use Pulsar\Context\RequestContext;
use Pulsar\Context\RequestContextHolder;
use Pulsar\ErrorHandling\DevelopmentRenderer;
use Pulsar\ErrorHandling\ExceptionHandler;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Observability\ErrorTracking\ErrorAggregatorInterface;
use Pulsar\Observability\ErrorTracking\ErrorEvent;
use Pulsar\Observability\ErrorTracking\SensitiveDataScrubber;
use Pulsar\Observability\Tracing\SpanId;
use Pulsar\Observability\Tracing\TraceContext;
use Pulsar\Observability\Tracing\TraceId;
use RuntimeException;

/**
 * Tests for ExceptionHandler error capture/aggregation logic.
 */
#[CoversClass(ExceptionHandler::class)]
final class ExceptionHandlerCaptureTest extends TestCase
{
    #[Test]
    public function capturesErrorToAggregator(): void
    {
        $captured = null;
        $aggregator = $this->createStub(ErrorAggregatorInterface::class);
        $aggregator->method('capture')->willReturnCallback(
            function (ErrorEvent $event) use (&$captured): void {
                $captured = $event;
            },
        );

        $handler = new ExceptionHandler(
            new DevelopmentRenderer(),
            errorAggregator: $aggregator,
        );

        $handler->handle(
            new RuntimeException('Captured error'),
            new ServerRequest(method: 'POST', uri: '/api/action'),
        );

        self::assertInstanceOf(ErrorEvent::class, $captured);
    }

    #[Test]
    public function captureIncludesCorrelationIdFromHolder(): void
    {
        $capturedEvent = null;
        $aggregator = $this->createStub(ErrorAggregatorInterface::class);
        $aggregator->method('capture')->willReturnCallback(
            function (ErrorEvent $event) use (&$capturedEvent): void {
                $capturedEvent = $event;
            },
        );

        $holder = new RequestContextHolder();
        $correlationId = CorrelationId::fromString(str_repeat('ab', 16));
        $holder->set(new RequestContext(
            correlationId: $correlationId,
            causationId: CausationId::fromString(str_repeat('cd', 16)),
        ));

        $handler = new ExceptionHandler(
            new DevelopmentRenderer(),
            errorAggregator: $aggregator,
            requestContextHolder: $holder,
        );

        $handler->handle(
            new RuntimeException('With correlation'),
            new ServerRequest(method: 'GET', uri: '/test'),
        );

        self::assertInstanceOf(ErrorEvent::class, $capturedEvent);
    }

    #[Test]
    public function captureExtractsTraceIdFromRequestAttribute(): void
    {
        $capturedEvent = null;
        $aggregator = $this->createStub(ErrorAggregatorInterface::class);
        $aggregator->method('capture')->willReturnCallback(
            function (ErrorEvent $event) use (&$capturedEvent): void {
                $capturedEvent = $event;
            },
        );

        $traceId = new TraceId(str_repeat('a1', 16));
        $spanId = new SpanId(str_repeat('b2', 8));
        $traceContext = new TraceContext($traceId, $spanId);

        $handler = new ExceptionHandler(
            new DevelopmentRenderer(),
            errorAggregator: $aggregator,
        );

        $request = new ServerRequest(
            method: 'GET',
            uri: '/traced',
            attributes: ['_trace_context' => $traceContext],
        );

        $handler->handle(new RuntimeException('Traced error'), $request);

        self::assertInstanceOf(ErrorEvent::class, $capturedEvent);
    }

    #[Test]
    public function captureAppliesScrubberToContext(): void
    {
        $capturedEvent = null;
        $aggregator = $this->createStub(ErrorAggregatorInterface::class);
        $aggregator->method('capture')->willReturnCallback(
            function (ErrorEvent $event) use (&$capturedEvent): void {
                $capturedEvent = $event;
            },
        );

        $scrubber = new SensitiveDataScrubber();

        $handler = new ExceptionHandler(
            new DevelopmentRenderer(),
            errorAggregator: $aggregator,
            scrubber: $scrubber,
        );

        $request = new ServerRequest(
            method: 'GET',
            uri: '/with-sensitive',
            queryParams: ['password' => 'secret123', 'page' => '1'],
        );

        $handler->handle(new RuntimeException('Sensitive data'), $request);

        self::assertInstanceOf(ErrorEvent::class, $capturedEvent);
    }

    #[Test]
    public function worksWithoutAggregator(): void
    {
        $handler = new ExceptionHandler(new DevelopmentRenderer());

        $response = $handler->handle(
            new RuntimeException('No aggregator'),
            new ServerRequest(method: 'GET', uri: '/test'),
        );

        self::assertSame(500, $response->getStatusCode());
    }

    #[Test]
    public function resolveRequestContextFromRequestAttribute(): void
    {
        $logger = new TestLogger();
        $correlationId = CorrelationId::fromString(str_repeat('ff', 16));
        $context = new RequestContext(
            correlationId: $correlationId,
            causationId: CausationId::fromString(str_repeat('ee', 16)),
        );

        $handler = new ExceptionHandler(
            new DevelopmentRenderer(),
            logger: $logger,
        );

        $request = new ServerRequest(
            method: 'GET',
            uri: '/from-attribute',
            attributes: ['_request_context' => $context],
        );

        $handler->handle(new RuntimeException('From attribute'), $request);

        self::assertCount(1, $logger->logs);
        self::assertSame(str_repeat('ff', 16), $logger->logs[0]['context']['correlation_id']);
    }

    #[Test]
    public function holderTakesPrecedenceOverAttribute(): void
    {
        $logger = new TestLogger();

        $holderCorrelation = CorrelationId::fromString(str_repeat('11', 16));
        $holder = new RequestContextHolder();
        $holder->set(new RequestContext(
            correlationId: $holderCorrelation,
            causationId: CausationId::fromString(str_repeat('22', 16)),
        ));

        $attrCorrelation = CorrelationId::fromString(str_repeat('33', 16));
        $attrContext = new RequestContext(
            correlationId: $attrCorrelation,
            causationId: CausationId::fromString(str_repeat('44', 16)),
        );

        $handler = new ExceptionHandler(
            new DevelopmentRenderer(),
            logger: $logger,
            requestContextHolder: $holder,
        );

        $request = new ServerRequest(
            method: 'GET',
            uri: '/precedence',
            attributes: ['_request_context' => $attrContext],
        );

        $handler->handle(new RuntimeException('Precedence test'), $request);

        self::assertCount(1, $logger->logs);
        self::assertSame(
            str_repeat('11', 16),
            $logger->logs[0]['context']['correlation_id'],
        );
    }
}
