<?php

declare(strict_types=1);

namespace Pulsar\Extension\Observability\Tests\Unit\Tracing\Sampling;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Observability\Tracing\Sampling\AlwaysSampler;
use Pulsar\Extension\Observability\Tracing\Sampling\NeverSampler;
use Pulsar\Extension\Observability\Tracing\Sampling\ParentBasedSampler;
use Pulsar\Extension\Observability\Tracing\Sampling\SamplingDecision;
use Pulsar\Observability\Tracing\SpanId;
use Pulsar\Observability\Tracing\TraceContext;
use Pulsar\Observability\Tracing\TraceId;

#[CoversClass(ParentBasedSampler::class)]
#[CoversClass(SamplingDecision::class)]
#[CoversClass(AlwaysSampler::class)]
#[CoversClass(NeverSampler::class)]
final class ParentBasedSamplerTest extends TestCase
{
    #[Test]
    public function parentSampledReturnsSampled(): void
    {
        $sampler = new ParentBasedSampler();
        $context = new TraceContext(
            new TraceId('0af7651916cd43dd8448eb211c80319c'),
            new SpanId('b7ad6b7169203331'),
            0x01, // sampled flag set
        );

        $decision = $sampler->shouldSample($context);

        self::assertTrue($decision->sampled);
        self::assertSame('parent_sampled', $decision->reason);
    }

    #[Test]
    public function parentNotSampledReturnsNotSampled(): void
    {
        $sampler = new ParentBasedSampler();
        $context = new TraceContext(
            new TraceId('0af7651916cd43dd8448eb211c80319c'),
            new SpanId('b7ad6b7169203331'),
            0x00, // sampled flag not set
        );

        $decision = $sampler->shouldSample($context);

        self::assertFalse($decision->sampled);
        self::assertSame('parent_not_sampled', $decision->reason);
    }

    #[Test]
    public function rootDelegationUsesRootSampler(): void
    {
        $sampler = new ParentBasedSampler(new NeverSampler());
        $context = new TraceContext(
            new TraceId('0af7651916cd43dd8448eb211c80319c'),
            new SpanId('b7ad6b7169203331'),
            0x01,
        );

        // shouldSampleRoot delegates to root sampler
        $decision = $sampler->shouldSampleRoot($context);

        self::assertFalse($decision->sampled);
        self::assertSame('never', $decision->reason);
    }

    #[Test]
    public function rootDelegationWithAlwaysSampler(): void
    {
        $sampler = new ParentBasedSampler(new AlwaysSampler());
        $context = new TraceContext(
            new TraceId('0af7651916cd43dd8448eb211c80319c'),
            new SpanId('b7ad6b7169203331'),
            0x00,
        );

        $decision = $sampler->shouldSampleRoot($context);

        self::assertTrue($decision->sampled);
        self::assertSame('always', $decision->reason);
    }

    #[Test]
    public function defaultRootSamplerIsAlways(): void
    {
        $sampler = new ParentBasedSampler();
        $context = new TraceContext(
            new TraceId('0af7651916cd43dd8448eb211c80319c'),
            new SpanId('b7ad6b7169203331'),
            0x00,
        );

        $decision = $sampler->shouldSampleRoot($context);

        self::assertTrue($decision->sampled);
        self::assertSame('always', $decision->reason);
    }
}
