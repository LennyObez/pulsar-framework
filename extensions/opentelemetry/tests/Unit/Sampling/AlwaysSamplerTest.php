<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Tests\Unit\Sampling;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\OpenTelemetry\Sampling\AlwaysSampler;
use Pulsar\Observability\Tracing\SpanId;
use Pulsar\Observability\Tracing\TraceContext;
use Pulsar\Observability\Tracing\TraceId;

#[CoversClass(AlwaysSampler::class)]
final class AlwaysSamplerTest extends TestCase
{
    #[Test]
    public function alwaysReturnsSampled(): void
    {
        $sampler = new AlwaysSampler();
        $context = TraceContext::create();
        $decision = $sampler->shouldSample($context);

        self::assertTrue($decision->sampled);
        self::assertSame('always', $decision->reason);
    }

    #[Test]
    public function samplesEvenWithUnsampledParent(): void
    {
        $sampler = new AlwaysSampler();
        $context = new TraceContext(TraceId::generate(), SpanId::generate(), traceFlags: 0x00);
        $decision = $sampler->shouldSample($context);

        self::assertTrue($decision->sampled);
    }
}
