<?php

declare(strict_types=1);

namespace Pulsar\Extension\Observability\Tests\Unit\Tracing\Bridge;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Observability\Tracing\Bridge\CompositeSpanProcessor;
use Pulsar\Observability\Tracing\Span;
use Pulsar\Observability\Tracing\SpanId;
use Pulsar\Observability\Tracing\SpanProcessorInterface;
use Pulsar\Observability\Tracing\TraceContext;
use Pulsar\Observability\Tracing\TraceId;

#[CoversClass(CompositeSpanProcessor::class)]
final class CompositeSpanProcessorTest extends TestCase
{
    #[Test]
    public function dispatchesToAllProcessors(): void
    {
        $span = $this->createSpan();

        $processor1 = $this->createMock(SpanProcessorInterface::class);
        $processor1->expects(self::once())->method('onEnd')->with($span);

        $processor2 = $this->createMock(SpanProcessorInterface::class);
        $processor2->expects(self::once())->method('onEnd')->with($span);

        $composite = new CompositeSpanProcessor([$processor1, $processor2]);
        $composite->onEnd($span);
    }

    #[Test]
    public function emptyProcessorListDoesNothing(): void
    {
        $composite = new CompositeSpanProcessor([]);
        $span = $this->createSpan();
        $composite->onEnd($span);

        // No processors to dispatch to: span's ended state was not altered by the composite
        self::assertFalse($span->hasEnded(), 'onEnd on composite must not call span->end()');
        self::assertInstanceOf(CompositeSpanProcessor::class, $composite);
    }

    #[Test]
    public function singleProcessorDispatches(): void
    {
        $span = $this->createSpan();

        $processor = $this->createMock(SpanProcessorInterface::class);
        $processor->expects(self::once())->method('onEnd')->with($span);

        $composite = new CompositeSpanProcessor([$processor]);
        $composite->onEnd($span);
    }

    private function createSpan(): Span
    {
        $context = new TraceContext(
            new TraceId('0af7651916cd43dd8448eb211c80319c'),
            new SpanId('b7ad6b7169203331'),
        );

        return new Span('test-span', $context);
    }
}
