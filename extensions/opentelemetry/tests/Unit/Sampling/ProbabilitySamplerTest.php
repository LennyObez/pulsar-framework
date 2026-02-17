<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Tests\Unit\Sampling;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\OpenTelemetry\Sampling\ProbabilitySampler;
use Pulsar\Observability\Tracing\TraceContext;
use Random\Engine\Mt19937;
use Random\Randomizer;

#[CoversClass(ProbabilitySampler::class)]
final class ProbabilitySamplerTest extends TestCase
{
    #[Test]
    public function fullProbabilityAlwaysSamples(): void
    {
        // Use a deterministic engine so getFloat always returns < 1.0
        $randomizer = new Randomizer(new Mt19937(42));
        $sampler = new ProbabilitySampler(probability: 1.0, randomizer: $randomizer);
        $context = TraceContext::create();

        $decision = $sampler->shouldSample($context);

        self::assertTrue($decision->sampled);
        self::assertSame('probability(1)', $decision->reason);
    }

    #[Test]
    public function zeroProbabilityNeverSamples(): void
    {
        $sampler = new ProbabilitySampler(probability: 0.0);
        $context = TraceContext::create();

        $decision = $sampler->shouldSample($context);

        self::assertFalse($decision->sampled);
        self::assertSame('probability(0)', $decision->reason);
    }

    #[Test]
    public function reasonContainsProbabilityValue(): void
    {
        $sampler = new ProbabilitySampler(probability: 0.5);
        $context = TraceContext::create();
        $decision = $sampler->shouldSample($context);

        self::assertSame('probability(0.5)', $decision->reason);
    }

    #[Test]
    public function statisticalDistributionIsReasonable(): void
    {
        // With p=0.5 over 1000 samples, we expect roughly 500 sampled
        $randomizer = new Randomizer(new Mt19937(12345));
        $sampler = new ProbabilitySampler(probability: 0.5, randomizer: $randomizer);
        $context = TraceContext::create();

        $sampledCount = 0;
        for ($i = 0; $i < 1000; $i++) {
            if ($sampler->shouldSample($context)->sampled) {
                $sampledCount++;
            }
        }

        // Allow a generous margin for a fixed-seed RNG
        self::assertGreaterThan(300, $sampledCount);
        self::assertLessThan(700, $sampledCount);
    }
}
