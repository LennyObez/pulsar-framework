<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Tests\Unit\Noop;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\OpenTelemetry\Noop\NoopSpanProcessor;
use Pulsar\Observability\Tracing\Span;
use Pulsar\Observability\Tracing\SpanProcessorInterface;
use Pulsar\Observability\Tracing\TraceContext;

#[CoversClass(NoopSpanProcessor::class)]
final class NoopSpanProcessorTest extends TestCase
{
    #[Test]
    public function implementsSpanProcessorInterface(): void
    {
        $processor = new NoopSpanProcessor();

        self::assertInstanceOf(SpanProcessorInterface::class, $processor);
    }

    #[Test]
    public function onEndDoesNotThrow(): void
    {
        $processor = new NoopSpanProcessor();
        $span = new Span(
            name: 'test-span',
            context: TraceContext::create(),
        );
        $span->end();

        $processor->onEnd($span);

        self::assertInstanceOf(NoopSpanProcessor::class, $processor);
    }
}
