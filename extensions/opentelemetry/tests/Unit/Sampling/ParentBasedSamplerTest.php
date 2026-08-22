<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Tests\Unit\Sampling;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\OpenTelemetry\Sampling\AlwaysSampler;
use Pulsar\Extension\OpenTelemetry\Sampling\NeverSampler;
use Pulsar\Extension\OpenTelemetry\Sampling\ParentBasedSampler;
use Pulsar\Observability\Tracing\SpanId;
use Pulsar\Observability\Tracing\TraceContext;
use Pulsar\Observability\Tracing\TraceId;

#[CoversClass(ParentBasedSampler::class)]
final class ParentBasedSamplerTest extends TestCase
{
    #[Test]
    public function sampledParentReturnsSampled(): void
    {
        $sampler = new ParentBasedSampler();
        $context = new TraceContext(TraceId::generate(), SpanId::generate(), traceFlags: 0x01);
        $decision = $sampler->shouldSample($context);

        self::assertTrue($decision->sampled);
        self::assertSame('parent_sampled', $decision->reason);
    }

    #[Test]
    public function unsampledParentReturnsNotSampled(): void
    {
        $sampler = new ParentBasedSampler();
        $context = new TraceContext(TraceId::generate(), SpanId::generate(), traceFlags: 0x00);
        $decision = $sampler->shouldSample($context);

        self::assertFalse($decision->sampled);
        self::assertSame('parent_not_sampled', $decision->reason);
    }

    #[Test]
    public function shouldSampleRootDelegatesToRootSampler(): void
    {
        $sampler = new ParentBasedSampler(new AlwaysSampler());
        $context = TraceContext::create();
        $decision = $sampler->shouldSampleRoot($context);

        self::assertTrue($decision->sampled);
        self::assertSame('always', $decision->reason);
    }

    #[Test]
    public function shouldSampleRootWithNeverRootSampler(): void
    {
        $sampler = new ParentBasedSampler(new NeverSampler());
        $context = TraceContext::create();
        $decision = $sampler->shouldSampleRoot($context);

        self::assertFalse($decision->sampled);
        self::assertSame('never', $decision->reason);
    }

    #[Test]
    public function defaultRootSamplerIsAlwaysSampler(): void
    {
        $sampler = new ParentBasedSampler();
        $context = TraceContext::create();
        $decision = $sampler->shouldSampleRoot($context);

        self::assertTrue($decision->sampled);
        self::assertSame('always', $decision->reason);
    }
}
