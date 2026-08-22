<?php

declare(strict_types=1);

namespace Pulsar\Extension\Observability\Tests\Unit\Tracing\Noop;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Observability\Tracing\Noop\NoopLogSink;
use Pulsar\Extension\Observability\Tracing\Noop\NoopSpanProcessor;
use Pulsar\Observability\Log\LogEntry;
use Pulsar\Observability\Log\LogSinkInterface;
use Pulsar\Observability\Tracing\Span;
use Pulsar\Observability\Tracing\SpanProcessorInterface;
use Pulsar\Observability\Tracing\TraceContext;

#[CoversClass(NoopLogSink::class)]
#[CoversClass(NoopSpanProcessor::class)]
final class NoopComponentsTest extends TestCase
{
    #[Test]
    public function noopLogSinkImplementsInterface(): void
    {
        $sink = new NoopLogSink();

        self::assertInstanceOf(LogSinkInterface::class, $sink);
    }

    #[Test]
    public function noopLogSinkWriteDoesNothing(): void
    {
        $this->expectNotToPerformAssertions();

        $sink = new NoopLogSink();
        $entry = $this->createStub(LogEntry::class);

        $sink->write($entry);
    }

    #[Test]
    public function noopSpanProcessorImplementsInterface(): void
    {
        $processor = new NoopSpanProcessor();

        self::assertInstanceOf(SpanProcessorInterface::class, $processor);
    }

    #[Test]
    public function noopSpanProcessorOnEndDoesNothing(): void
    {
        $this->expectNotToPerformAssertions();

        $processor = new NoopSpanProcessor();
        $context = TraceContext::create();
        $span = new Span('test-span', $context);

        $processor->onEnd($span);
    }
}
