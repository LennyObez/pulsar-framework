<?php

declare(strict_types=1);

namespace Pulsar\Extension\Observability\Tests\Unit\Tracing\Sampling;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Observability\Tracing\Sampling\AlwaysSampler;
use Pulsar\Extension\Observability\Tracing\Sampling\NeverSampler;
use Pulsar\Extension\Observability\Tracing\Sampling\SamplingDecision;
use Pulsar\Observability\Tracing\TraceContext;

#[CoversClass(AlwaysSampler::class)]
#[CoversClass(NeverSampler::class)]
#[CoversClass(SamplingDecision::class)]
final class SamplerDecisionAndSimpleSamplersTest extends TestCase
{
    #[Test]
    public function samplingDecisionConstruction(): void
    {
        $decision = new SamplingDecision(sampled: true, reason: 'always');

        self::assertTrue($decision->sampled);
        self::assertSame('always', $decision->reason);
    }

    #[Test]
    public function samplingDecisionDefaultReason(): void
    {
        $decision = new SamplingDecision(sampled: false);

        self::assertFalse($decision->sampled);
        self::assertSame('', $decision->reason);
    }

    #[Test]
    public function alwaysSamplerAlwaysSamples(): void
    {
        $sampler = new AlwaysSampler();
        $context = TraceContext::create();

        $decision = $sampler->shouldSample($context);

        self::assertTrue($decision->sampled);
        self::assertSame('always', $decision->reason);
    }

    #[Test]
    public function neverSamplerNeverSamples(): void
    {
        $sampler = new NeverSampler();
        $context = TraceContext::create();

        $decision = $sampler->shouldSample($context);

        self::assertFalse($decision->sampled);
        self::assertSame('never', $decision->reason);
    }
}
