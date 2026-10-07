<?php

declare(strict_types=1);

namespace Pulsar\Extension\Observability\Tests\Unit\Tracing\Sampling;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Observability\Tracing\Sampling\AlwaysSampler;
use Pulsar\Extension\Observability\Tracing\Sampling\NeverSampler;
use Pulsar\Extension\Observability\Tracing\Sampling\ParentBasedSampler;
use Pulsar\Extension\Observability\Tracing\Sampling\ProbabilitySampler;
use Pulsar\Extension\Observability\Tracing\Sampling\RateLimitedSampler;
use Pulsar\Observability\Tracing\SpanId;
use Pulsar\Observability\Tracing\TraceContext;
use Pulsar\Observability\Tracing\TraceId;

#[CoversClass(AlwaysSampler::class)]
#[CoversClass(NeverSampler::class)]
#[CoversClass(ParentBasedSampler::class)]
#[CoversClass(ProbabilitySampler::class)]
#[CoversClass(RateLimitedSampler::class)]
final class SamplerDeepTest extends TestCase
{
    #[Test]
    public function alwaysSamplerReturnsSampled(): void
    {
        $sampler = new AlwaysSampler();
        $ctx = TraceContext::create();

        $decision = $sampler->shouldSample($ctx);

        self::assertTrue($decision->sampled);
        self::assertSame('always', $decision->reason);
    }

    #[Test]
    public function neverSamplerReturnsNotSampled(): void
    {
        $sampler = new NeverSampler();
        $ctx = TraceContext::create();

        $decision = $sampler->shouldSample($ctx);

        self::assertFalse($decision->sampled);
        self::assertSame('never', $decision->reason);
    }

    #[Test]
    public function parentBasedSamplerWithSampledParent(): void
    {
        $sampler = new ParentBasedSampler();
        // TraceContext::create() defaults to sampled (traceFlags=0x01)
        $ctx = TraceContext::create();

        $decision = $sampler->shouldSample($ctx);

        self::assertTrue($decision->sampled);
        self::assertSame('parent_sampled', $decision->reason);
    }

    #[Test]
    public function parentBasedSamplerWithUnsampledParent(): void
    {
        $sampler = new ParentBasedSampler();
        // Create a context with traceFlags=0 (not sampled)
        $ctx = new TraceContext(
            traceId: new TraceId(str_repeat('a', 32)),
            spanId: new SpanId(str_repeat('b', 16)),
            traceFlags: 0x00,
        );

        $decision = $sampler->shouldSample($ctx);

        self::assertFalse($decision->sampled);
        self::assertSame('parent_not_sampled', $decision->reason);
    }

    #[Test]
    public function parentBasedSamplerRootWithDefaultSampler(): void
    {
        $sampler = new ParentBasedSampler();
        $ctx = TraceContext::create();

        $decision = $sampler->shouldSampleRoot($ctx);

        // Default root sampler is AlwaysSampler
        self::assertTrue($decision->sampled);
    }

    #[Test]
    public function parentBasedSamplerRootWithNeverSampler(): void
    {
        $sampler = new ParentBasedSampler(new NeverSampler());
        $ctx = TraceContext::create();

        $decision = $sampler->shouldSampleRoot($ctx);

        self::assertFalse($decision->sampled);
    }

    #[Test]
    public function probabilitySamplerAlwaysAtOne(): void
    {
        $sampler = new ProbabilitySampler(1.0);
        $ctx = TraceContext::create();

        $decision = $sampler->shouldSample($ctx);

        self::assertTrue($decision->sampled);
    }

    #[Test]
    public function probabilitySamplerNeverAtZero(): void
    {
        $sampler = new ProbabilitySampler(0.0);
        $ctx = TraceContext::create();

        $decision = $sampler->shouldSample($ctx);

        self::assertFalse($decision->sampled);
    }

    #[Test]
    public function rateLimitedSamplerAllowsInitialBurst(): void
    {
        $sampler = new RateLimitedSampler(ratePerSecond: 10.0);
        $ctx = TraceContext::create();

        // First call should be sampled (initial burst = ratePerSecond)
        $decision = $sampler->shouldSample($ctx);
        self::assertTrue($decision->sampled);
        self::assertStringContainsString('rate_limited', $decision->reason);
    }

    #[Test]
    public function rateLimitedSamplerExhaustsBucket(): void
    {
        $sampler = new RateLimitedSampler(ratePerSecond: 1.0, maxBurst: 2.0);
        $ctx = TraceContext::create();

        // Use up the 2 tokens
        self::assertTrue($sampler->shouldSample($ctx)->sampled);
        self::assertTrue($sampler->shouldSample($ctx)->sampled);

        // Third call should be rejected
        self::assertFalse($sampler->shouldSample($ctx)->sampled);
    }

    #[Test]
    public function rateLimitedSamplerWithZeroBurstUsesRateAsDefault(): void
    {
        $sampler = new RateLimitedSampler(ratePerSecond: 1.0, maxBurst: 0.0);
        $ctx = TraceContext::create();

        // maxBurst=0 defaults to ratePerSecond=1.0 (1 token)
        self::assertTrue($sampler->shouldSample($ctx)->sampled);
        self::assertFalse($sampler->shouldSample($ctx)->sampled);
    }
}
