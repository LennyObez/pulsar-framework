<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\OpenTelemetry\Sampling;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\OpenTelemetry\Sampling\RateLimitedSampler;
use Pulsar\Extension\OpenTelemetry\Sampling\SamplingDecision;
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
        $sampler = $this->sampler(ratePerSecond: 5.0, maxBurst: 3.0);
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
        $sampler = $this->sampler(ratePerSecond: 2.0, maxBurst: 2.0);
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
        $sampler = $this->sampler(ratePerSecond: 3.0);
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
        $sampler = $this->sampler(ratePerSecond: 100.0, maxBurst: 1.0);
        $context = $this->createContext();

        // Exhaust the single token
        $decision = $sampler->shouldSample($context);
        self::assertTrue($decision->sampled);

        $decision = $sampler->shouldSample($context);
        self::assertFalse($decision->sampled);

        // At 100 tokens/s, 60ms yields ~6 tokens — well above the 1.0 needed.
        $this->advance(0.06);

        $decision = $sampler->shouldSample($context);
        self::assertTrue($decision->sampled);
    }

    #[Test]
    public function reasonIncludesRate(): void
    {
        $sampler = $this->sampler(ratePerSecond: 10.0);
        $context = $this->createContext();

        $decision = $sampler->shouldSample($context);
        self::assertSame('rate_limited(10/s)', $decision->reason);
    }


    /**
     * Nanoseconds the injected clock reports. Time only moves when a test moves it.
     *
     * These cases used to rely on real elapsed time: exhaust the bucket, assert the
     * next call is refused, and trust that no refill happened in between. At 100
     * tokens per second a token returns in 10 ms, so under code coverage the two
     * consecutive calls already refilled what the test had just emptied and the
     * suite failed on correct code. Driving the clock removes the race and the
     * sleep with it.
     */
    private int $now = 0;

    private function sampler(float $ratePerSecond, float $maxBurst = 0.0): RateLimitedSampler
    {
        $this->now = 0;

        return new RateLimitedSampler($ratePerSecond, $maxBurst, fn(): int => $this->now);
    }

    private function advance(float $seconds): void
    {
        $this->now += (int) ($seconds * 1_000_000_000.0);
    }

    private function createContext(): TraceContext
    {
        return new TraceContext(
            new TraceId('0af7651916cd43dd8448eb211c80319c'),
            new SpanId('b7ad6b7169203331'),
        );
    }
}
