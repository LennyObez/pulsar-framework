<?php

declare(strict_types=1);

namespace Pulsar\Extension\Observability\Tests\Unit\Tracing\Sampling;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Observability\Tracing\Sampling\ProbabilitySampler;
use Pulsar\Extension\Observability\Tracing\Sampling\SamplingDecision;
use Pulsar\Observability\Tracing\SpanId;
use Pulsar\Observability\Tracing\TraceContext;
use Pulsar\Observability\Tracing\TraceId;
use Random\Engine\Mt19937;
use Random\Randomizer;

#[CoversClass(ProbabilitySampler::class)]
#[CoversClass(SamplingDecision::class)]
final class ProbabilitySamplerTest extends TestCase
{
    #[Test]
    public function probabilityZeroNeverSamples(): void
    {
        $sampler = new ProbabilitySampler(0.0, new Randomizer(new Mt19937(42)));
        $context = $this->createContext();

        for ($i = 0; $i < 100; $i++) {
            $decision = $sampler->shouldSample($context);
            self::assertFalse($decision->sampled);
            self::assertSame('probability(0)', $decision->reason);
        }
    }

    #[Test]
    public function probabilityOneAlwaysSamples(): void
    {
        $sampler = new ProbabilitySampler(1.0, new Randomizer(new Mt19937(42)));
        $context = $this->createContext();

        for ($i = 0; $i < 100; $i++) {
            $decision = $sampler->shouldSample($context);
            self::assertTrue($decision->sampled);
            self::assertSame('probability(1)', $decision->reason);
        }
    }

    #[Test]
    public function probabilityHalfSamplesApproximatelyHalf(): void
    {
        $sampler = new ProbabilitySampler(0.5, new Randomizer(new Mt19937(12345)));
        $context = $this->createContext();

        $sampledCount = 0;
        $total = 1000;

        for ($i = 0; $i < $total; $i++) {
            $decision = $sampler->shouldSample($context);

            if ($decision->sampled) {
                $sampledCount++;
            }

            self::assertSame('probability(0.5)', $decision->reason);
        }

        // With 1000 samples at 50%, expect between 400 and 600
        self::assertGreaterThan(350, $sampledCount);
        self::assertLessThan(650, $sampledCount);
    }

    #[Test]
    public function seededRandomizerProducesDeterministicResults(): void
    {
        $contextA = $this->createContext();
        $contextB = $this->createContext();

        $samplerA = new ProbabilitySampler(0.5, new Randomizer(new Mt19937(99)));
        $samplerB = new ProbabilitySampler(0.5, new Randomizer(new Mt19937(99)));

        for ($i = 0; $i < 50; $i++) {
            $decisionA = $samplerA->shouldSample($contextA);
            $decisionB = $samplerB->shouldSample($contextB);
            self::assertSame($decisionA->sampled, $decisionB->sampled);
        }
    }

    private function createContext(): TraceContext
    {
        return new TraceContext(
            new TraceId('0af7651916cd43dd8448eb211c80319c'),
            new SpanId('b7ad6b7169203331'),
        );
    }
}
