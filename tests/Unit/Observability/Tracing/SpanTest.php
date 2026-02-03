<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Observability\Tracing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Observability\Tracing\Span;
use Pulsar\Observability\Tracing\SpanStatus;
use Pulsar\Observability\Tracing\TraceContext;

#[CoversClass(Span::class)]
final class SpanTest extends TestCase
{
    #[Test]
    public function hasNameAndContext(): void
    {
        $context = TraceContext::create();
        $span = new Span('test-span', $context);

        self::assertSame('test-span', $span->name);
        self::assertSame($context, $span->context);
    }

    #[Test]
    public function startsWithUnsetStatusAndNoEnd(): void
    {
        $span = new Span('test', TraceContext::create());

        self::assertSame(SpanStatus::Unset, $span->status());
        self::assertFalse($span->hasEnded());
        self::assertNull($span->endTime());
        self::assertNull($span->duration());
    }

    #[Test]
    public function endSetsEndTime(): void
    {
        $span = new Span('test', TraceContext::create());
        $span->end();

        self::assertTrue($span->hasEnded());
        self::assertNotNull($span->endTime());
        self::assertNotNull($span->duration());
        self::assertGreaterThanOrEqual(0, $span->duration());
    }

    #[Test]
    public function endIsIdempotent(): void
    {
        $span = new Span('test', TraceContext::create());
        $span->end();
        $endTime = $span->endTime();

        // Second end should not change the time
        $span->end();

        self::assertSame($endTime, $span->endTime());
    }

    #[Test]
    public function setStatusChangesStatus(): void
    {
        $span = new Span('test', TraceContext::create());
        $span->setStatus(SpanStatus::Ok);

        self::assertSame(SpanStatus::Ok, $span->status());

        $span->setStatus(SpanStatus::Error);

        self::assertSame(SpanStatus::Error, $span->status());
    }

    #[Test]
    public function setAttributeAddsAttributes(): void
    {
        $span = new Span('test', TraceContext::create());
        $span->setAttribute('http.method', 'GET');
        $span->setAttribute('http.status', 200);
        $span->setAttribute('sampled', true);

        $attrs = $span->attributes();
        self::assertSame('GET', $attrs['http.method']);
        self::assertSame(200, $attrs['http.status']);
        self::assertTrue($attrs['sampled']);
    }

    #[Test]
    public function durationSecondsReturnsFloat(): void
    {
        $span = new Span('test', TraceContext::create());
        $span->end();

        $seconds = $span->durationSeconds();
        self::assertNotNull($seconds);
        self::assertGreaterThanOrEqual(0.0, $seconds);
    }
}
