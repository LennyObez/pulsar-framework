<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Tests\Unit\Sampling;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\OpenTelemetry\Sampling\RateLimitedSampler;
use Pulsar\Observability\Tracing\TraceContext;

#[CoversClass(RateLimitedSampler::class)]
final class RateLimitedSamplerTest extends TestCase
{
    #[Test]
    public function initialBurstIsAllowed(): void
    {
        $sampler = $this->sampler(ratePerSecond: 5.0);
        $context = TraceContext::create();

        // Initial tokens = ratePerSecond = 5, so 5 should succeed
        for ($i = 0; $i < 5; $i++) {
            $decision = $sampler->shouldSample($context);
            self::assertTrue($decision->sampled, "Sample $i should succeed");
        }
    }

    #[Test]
    public function tokenExhaustionDropsSamples(): void
    {
        $sampler = $this->sampler(ratePerSecond: 2.0);
        $context = TraceContext::create();

        // Exhaust initial 2 tokens
        $sampler->shouldSample($context);
        $sampler->shouldSample($context);

        // Third should fail (no time for refill)
        $decision = $sampler->shouldSample($context);
        self::assertFalse($decision->sampled);
    }

    #[Test]
    public function reasonContainsRate(): void
    {
        $sampler = $this->sampler(ratePerSecond: 10.0);
        $context = TraceContext::create();
        $decision = $sampler->shouldSample($context);

        self::assertSame('rate_limited(10/s)', $decision->reason);
    }

    #[Test]
    public function customMaxBurstLimitsInitialTokens(): void
    {
        $sampler = $this->sampler(ratePerSecond: 100.0, maxBurst: 3.0);
        $context = TraceContext::create();

        // Should get 3 tokens (maxBurst=3), not 100
        for ($i = 0; $i < 3; $i++) {
            self::assertTrue($sampler->shouldSample($context)->sampled);
        }

        // Fourth should fail
        self::assertFalse($sampler->shouldSample($context)->sampled);
    }

    /**
     * Nanoseconds the injected clock reports. Time only moves when a test moves it.
     *
     * These cases relied on real elapsed time: exhaust the bucket, assert the next
     * call is refused, and trust no refill happened in between. At 100 tokens per
     * second a token returns in 10 ms, so under code coverage the two consecutive
     * calls already refilled what the test had just emptied, and the suite failed
     * on correct code.
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
}
