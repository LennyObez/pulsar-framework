<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Tests\Unit\Sampling;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\OpenTelemetry\Sampling\SamplingDecision;

#[CoversClass(SamplingDecision::class)]
final class SamplingDecisionTest extends TestCase
{
    #[Test]
    public function sampledDecision(): void
    {
        $decision = new SamplingDecision(sampled: true, reason: 'always');

        self::assertTrue($decision->sampled);
        self::assertSame('always', $decision->reason);
    }

    #[Test]
    public function notSampledDecision(): void
    {
        $decision = new SamplingDecision(sampled: false, reason: 'never');

        self::assertFalse($decision->sampled);
        self::assertSame('never', $decision->reason);
    }

    #[Test]
    public function defaultReasonIsEmptyString(): void
    {
        $decision = new SamplingDecision(sampled: true);

        self::assertSame('', $decision->reason);
    }
}
