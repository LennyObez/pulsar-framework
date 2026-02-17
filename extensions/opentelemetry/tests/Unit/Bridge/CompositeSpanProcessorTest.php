<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Tests\Unit\Bridge;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\OpenTelemetry\Bridge\CompositeSpanProcessor;
use Pulsar\Observability\Tracing\Span;
use Pulsar\Observability\Tracing\SpanProcessorInterface;
use Pulsar\Observability\Tracing\TraceContext;

#[CoversClass(CompositeSpanProcessor::class)]
final class CompositeSpanProcessorTest extends TestCase
{
    #[Test]
    public function implementsSpanProcessorInterface(): void
    {
        $composite = new CompositeSpanProcessor();

        self::assertInstanceOf(SpanProcessorInterface::class, $composite);
    }

    #[Test]
    public function onEndDispatchesToAllProcessors(): void
    {
        $bag = new ProcessorCallBag();

        $processor1 = new class ($bag) implements SpanProcessorInterface {
            public function __construct(private readonly ProcessorCallBag $bag) {}

            public function onEnd(Span $span): void
            {
                $this->bag->calls[] = 'p1:' . $span->name;
            }
        };

        $processor2 = new class ($bag) implements SpanProcessorInterface {
            public function __construct(private readonly ProcessorCallBag $bag) {}

            public function onEnd(Span $span): void
            {
                $this->bag->calls[] = 'p2:' . $span->name;
            }
        };

        $composite = new CompositeSpanProcessor([$processor1, $processor2]);
        $span = new Span(name: 'test-span', context: TraceContext::create());
        $span->end();

        $composite->onEnd($span);

        self::assertSame(['p1:test-span', 'p2:test-span'], $bag->calls);
    }

    #[Test]
    public function onEndWithNoProcessorsDoesNothing(): void
    {
        $composite = new CompositeSpanProcessor([]);
        $span = new Span(name: 'test-span', context: TraceContext::create());
        $span->end();

        // Should not throw
        $composite->onEnd($span);

        self::assertInstanceOf(CompositeSpanProcessor::class, $composite);
    }
}

/**
 * Mutable bag for tracking processor calls, avoiding by-ref property issues.
 */
final class ProcessorCallBag
{
    /** @var list<string> */
    public array $calls = [];
}
