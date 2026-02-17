<?php

declare(strict_types=1);

namespace Pulsar\Extension\Observability\Tests\Unit\Tracing\Sampling;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Observability\Tracing\Sampling\RateLimitedSampler;
use Pulsar\Extension\Observability\Tracing\Sampling\SamplingDecision;
use Pulsar\Observability\Tracing\SpanId;
use Pulsar\Observability\Tracing\TraceContext;
use Pulsar\Observability\Tracing\TraceId;

#[CoversClass(RateLimitedSampler::class)]
#[CoversClass(SamplingDecision::class)]
final class RateLimitedSamplerTest extends TestCase
{
    #[Test]
    public function initialBurstAllowsSampling(): void
    {
        $sampler = new RateLimitedSampler(ratePerSecond: 5.0, maxBurst: 3.0);
        $context = $this->createContext();

        // Should allow 3 samples (maxBurst = 3)
        for ($i = 0; $i < 3; $i++) {
            $decision = $sampler->shouldSample($context);
            self::assertTrue($decision->sampled, "Sample {$i} should be allowed");
            self::assertSame('rate_limited(5/s)', $decision->reason);
        }
    }

    #[Test]
    public function tokensExhaustAfterBurst(): void
    {
        $sampler = new RateLimitedSampler(ratePerSecond: 2.0, maxBurst: 2.0);
        $context = $this->createContext();

        // Exhaust both tokens
        $sampler->shouldSample($context);
        $sampler->shouldSample($context);

        // Third should be denied (no time for refill in same test)
        $decision = $sampler->shouldSample($context);
        self::assertFalse($decision->sampled);
        self::assertSame('rate_limited(2/s)', $decision->reason);
    }

    #[Test]
    public function defaultMaxBurstEqualsRate(): void
    {
        // maxBurst defaults to 0.0, which means effective maxBurst = ratePerSecond
        $sampler = new RateLimitedSampler(ratePerSecond: 3.0);
        $context = $this->createContext();

        // Should allow 3 samples (effective maxBurst = ratePerSecond = 3)
        for ($i = 0; $i < 3; $i++) {
            $decision = $sampler->shouldSample($context);
            self::assertTrue($decision->sampled, "Sample {$i} should be allowed");
        }

        // Fourth should fail
        $decision = $sampler->shouldSample($context);
        self::assertFalse($decision->sampled);
    }

    #[Test]
    public function tokensRefillOverTime(): void
    {
        $sampler = new RateLimitedSampler(ratePerSecond: 100.0, maxBurst: 1.0);
        $context = $this->createContext();

        // Exhaust the single token
        $decision = $sampler->shouldSample($context);
        self::assertTrue($decision->sampled);

        $decision = $sampler->shouldSample($context);
        self::assertFalse($decision->sampled);

        // At 100 tokens/s, 60ms yields ~6 tokens: well above the 1.0 needed.
        // 60ms comfortably exceeds Windows timer granularity (~15ms).
        usleep(60_000);

        $decision = $sampler->shouldSample($context);
        self::assertTrue($decision->sampled);
    }

    #[Test]
    public function reasonIncludesRate(): void
    {
        $sampler = new RateLimitedSampler(ratePerSecond: 10.0);
        $context = $this->createContext();

        $decision = $sampler->shouldSample($context);
        self::assertSame('rate_limited(10/s)', $decision->reason);
    }

    private function createContext(): TraceContext
    {
        return new TraceContext(
            new TraceId('0af7651916cd43dd8448eb211c80319c'),
            new SpanId('b7ad6b7169203331'),
        );
    }
}
