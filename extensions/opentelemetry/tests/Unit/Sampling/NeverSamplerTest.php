<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Tests\Unit\Sampling;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\OpenTelemetry\Sampling\NeverSampler;
use Pulsar\Observability\Tracing\SpanId;
use Pulsar\Observability\Tracing\TraceContext;
use Pulsar\Observability\Tracing\TraceId;

#[CoversClass(NeverSampler::class)]
final class NeverSamplerTest extends TestCase
{
    #[Test]
    public function neverReturnsSampled(): void
    {
        $sampler = new NeverSampler();
        $context = TraceContext::create();
        $decision = $sampler->shouldSample($context);

        self::assertFalse($decision->sampled);
        self::assertSame('never', $decision->reason);
    }

    #[Test]
    public function dropsEvenWithSampledParent(): void
    {
        $sampler = new NeverSampler();
        $context = new TraceContext(TraceId::generate(), SpanId::generate(), traceFlags: 0x01);
        $decision = $sampler->shouldSample($context);

        self::assertFalse($decision->sampled);
    }
}
