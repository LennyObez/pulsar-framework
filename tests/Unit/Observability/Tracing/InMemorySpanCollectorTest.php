<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Observability\Tracing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Observability\Tracing\InMemorySpanCollector;
use Pulsar\Observability\Tracing\Span;
use Pulsar\Observability\Tracing\TraceContext;

#[CoversClass(InMemorySpanCollector::class)]
final class InMemorySpanCollectorTest extends TestCase
{
    #[Test]
    public function collectsSpans(): void
    {
        $collector = new InMemorySpanCollector();
        $span = new Span('test', TraceContext::create());
        $span->end();

        $collector->onEnd($span);

        self::assertSame(1, $collector->count());
        self::assertSame($span, $collector->spans()[0]);
    }

    #[Test]
    public function evictsOldestWhenExceedingMax(): void
    {
        $collector = new InMemorySpanCollector(maxSpans: 3);

        for ($i = 0; $i < 5; $i++) {
            $span = new Span("span-$i", TraceContext::create());
            $span->end();
            $collector->onEnd($span);
        }

        self::assertSame(3, $collector->count());
        self::assertSame('span-2', $collector->spans()[0]->name);
        self::assertSame('span-4', $collector->spans()[2]->name);
    }

    #[Test]
    public function filtersByTraceId(): void
    {
        $collector = new InMemorySpanCollector();
        $ctx1 = TraceContext::create();
        $ctx2 = TraceContext::create();

        $span1 = new Span('s1', $ctx1);
        $span1->end();
        $collector->onEnd($span1);

        $span2 = new Span('s2', $ctx2);
        $span2->end();
        $collector->onEnd($span2);

        $span3 = new Span('s3', $ctx1->createChild());
        $span3->end();
        $collector->onEnd($span3);

        $traceSpans = $collector->spansByTraceId($ctx1->traceId);
        self::assertCount(2, $traceSpans);
    }

    #[Test]
    public function clearRemovesAllSpans(): void
    {
        $collector = new InMemorySpanCollector();
        $span = new Span('test', TraceContext::create());
        $span->end();
        $collector->onEnd($span);

        $collector->clear();

        self::assertSame(0, $collector->count());
        self::assertSame([], $collector->spans());
    }
}
