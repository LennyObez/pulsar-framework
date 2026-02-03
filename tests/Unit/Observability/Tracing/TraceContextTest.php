<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Observability\Tracing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Observability\Tracing\SpanId;
use Pulsar\Observability\Tracing\TraceContext;
use Pulsar\Observability\Tracing\TraceId;

use function strlen;

#[CoversClass(TraceContext::class)]
final class TraceContextTest extends TestCase
{
    #[Test]
    public function createGeneratesNewIds(): void
    {
        $ctx = TraceContext::create();

        self::assertSame(32, strlen($ctx->traceId->value));
        self::assertSame(16, strlen($ctx->spanId->value));
        self::assertTrue($ctx->isSampled());
    }

    #[Test]
    public function createChildPreservesTraceId(): void
    {
        $parent = TraceContext::create();
        $child = $parent->createChild();

        self::assertSame($parent->traceId->value, $child->traceId->value);
        self::assertNotSame($parent->spanId->value, $child->spanId->value);
        self::assertSame($parent->traceFlags, $child->traceFlags);
    }

    #[Test]
    public function isSampledChecksFlagBit(): void
    {
        $sampled = new TraceContext(
            TraceId::generate(),
            SpanId::generate(),
            0x01,
        );
        $notSampled = new TraceContext(
            TraceId::generate(),
            SpanId::generate(),
            0x00,
        );

        self::assertTrue($sampled->isSampled());
        self::assertFalse($notSampled->isSampled());
    }

    #[Test]
    public function flagsArePreservedInChild(): void
    {
        $parent = new TraceContext(
            TraceId::generate(),
            SpanId::generate(),
            0x00,
        );
        $child = $parent->createChild();

        self::assertFalse($child->isSampled());
    }
}
