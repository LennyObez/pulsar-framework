<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\ZeroTrust\TrustScore;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\ZeroTrust\Claim\Claim;
use Pulsar\Security\ZeroTrust\Claim\ClaimSet;
use Pulsar\Security\ZeroTrust\Claim\ClaimSource;
use Pulsar\Security\ZeroTrust\TrustScore\Internal\TrustScoreCalculator;
use Pulsar\Security\ZeroTrust\TrustScore\TrustScoreResult;

#[CoversClass(TrustScoreCalculator::class)]
final class TrustScoreCalculatorTest extends TestCase
{
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable();
    }

    #[Test]
    public function returnsZeroScoreForEmptyClaimSet(): void
    {
        $result = TrustScoreCalculator::fromClaims(new ClaimSet(), ['device.registered' => 1.0]);

        self::assertSame(0.0, $result->score);
        self::assertSame([], $result->explanations);
        self::assertCount(0, $result->claimSnapshot);
    }

    #[Test]
    public function returnsZeroScoreWhenNoClaimsMatchWeights(): void
    {
        $claims = new ClaimSet([
            new Claim('network.internal', true, ClaimSource::NetworkSignal, 0.9, $this->now),
        ]);

        $result = TrustScoreCalculator::fromClaims($claims, ['device.registered' => 1.0]);

        self::assertSame(0.0, $result->score);
        self::assertSame([], $result->explanations);
    }

    #[Test]
    public function computesSingleClaimScore(): void
    {
        $claims = new ClaimSet([
            new Claim('device.registered', true, ClaimSource::DeviceSignal, 0.8, $this->now),
        ]);

        $result = TrustScoreCalculator::fromClaims($claims, ['device.registered' => 1.0]);

        self::assertSame(0.8, $result->score);
        self::assertCount(1, $result->explanations);
        self::assertSame('device.registered', $result->explanations[0]->claimName);
        self::assertSame(1.0, $result->explanations[0]->weight);
        self::assertSame(0.8, $result->explanations[0]->claimConfidence);
        self::assertSame(0.8, $result->explanations[0]->contribution);
    }

    #[Test]
    public function computesWeightedAverageOfMultipleClaims(): void
    {
        $claims = new ClaimSet([
            new Claim('device.registered', true, ClaimSource::DeviceSignal, 1.0, $this->now),
            new Claim('ip.in_range', true, ClaimSource::NetworkSignal, 0.5, $this->now),
        ]);

        // weight 2.0 * confidence 1.0 = 2.0
        // weight 1.0 * confidence 0.5 = 0.5
        // total weight = 3.0, weighted sum = 2.5, score = 2.5/3.0 = 0.8333...
        $result = TrustScoreCalculator::fromClaims($claims, [
            'device.registered' => 2.0,
            'ip.in_range' => 1.0,
        ]);

        self::assertEqualsWithDelta(0.8333, $result->score, 0.001);
        self::assertCount(2, $result->explanations);
    }

    #[Test]
    public function clampsScoreToMaximumOnePointZero(): void
    {
        // All claims at full confidence with positive weights — score should be exactly 1.0
        $claims = new ClaimSet([
            new Claim('device.registered', true, ClaimSource::DeviceSignal, 1.0, $this->now),
            new Claim('ip.in_range', true, ClaimSource::NetworkSignal, 1.0, $this->now),
        ]);

        $result = TrustScoreCalculator::fromClaims($claims, [
            'device.registered' => 1.0,
            'ip.in_range' => 1.0,
        ]);

        self::assertSame(1.0, $result->score);
    }

    #[Test]
    public function clampsScoreToMinimumZero(): void
    {
        // Negative weight with high confidence could push below zero
        $claims = new ClaimSet([
            new Claim('anomaly.detected', true, ClaimSource::BehaviorSignal, 1.0, $this->now),
        ]);

        $result = TrustScoreCalculator::fromClaims($claims, ['anomaly.detected' => -2.0]);

        self::assertSame(0.0, $result->score);
    }

    #[Test]
    public function explanationsShowPerClaimContribution(): void
    {
        $claims = new ClaimSet([
            new Claim('device.registered', true, ClaimSource::DeviceSignal, 0.9, $this->now),
            new Claim('time.business_hours', true, ClaimSource::TimeSignal, 0.7, $this->now),
        ]);

        $result = TrustScoreCalculator::fromClaims($claims, [
            'device.registered' => 0.6,
            'time.business_hours' => 0.4,
        ]);

        self::assertCount(2, $result->explanations);

        $deviceExplanation = $result->explanations[0];
        self::assertSame('device.registered', $deviceExplanation->claimName);
        self::assertSame(0.6, $deviceExplanation->weight);
        self::assertSame(0.9, $deviceExplanation->claimConfidence);
        self::assertEqualsWithDelta(0.54, $deviceExplanation->contribution, 0.001);

        $timeExplanation = $result->explanations[1];
        self::assertSame('time.business_hours', $timeExplanation->claimName);
        self::assertSame(0.4, $timeExplanation->weight);
        self::assertSame(0.7, $timeExplanation->claimConfidence);
        self::assertEqualsWithDelta(0.28, $timeExplanation->contribution, 0.001);
    }

    #[Test]
    public function ignoresClaimsNotInWeightMap(): void
    {
        $claims = new ClaimSet([
            new Claim('device.registered', true, ClaimSource::DeviceSignal, 0.9, $this->now),
            new Claim('unweighted.claim', true, ClaimSource::NetworkSignal, 1.0, $this->now),
        ]);

        $result = TrustScoreCalculator::fromClaims($claims, ['device.registered' => 1.0]);

        self::assertSame(0.9, $result->score);
        self::assertCount(1, $result->explanations);
    }

    #[Test]
    public function snapshotContainsOriginalClaims(): void
    {
        $claims = new ClaimSet([
            new Claim('device.registered', true, ClaimSource::DeviceSignal, 0.9, $this->now),
        ]);

        $result = TrustScoreCalculator::fromClaims($claims, ['device.registered' => 1.0]);

        self::assertCount(1, $result->claimSnapshot);
        self::assertSame($claims, $result->claimSnapshot);
    }

    #[Test]
    public function handlesNegativeWeightsInMixedSet(): void
    {
        $claims = new ClaimSet([
            new Claim('device.registered', true, ClaimSource::DeviceSignal, 1.0, $this->now),
            new Claim('anomaly.detected', true, ClaimSource::BehaviorSignal, 0.8, $this->now),
        ]);

        // device: 1.0 * 1.0 = 1.0 (positive)
        // anomaly: -0.5 * 0.8 = -0.4 (negative)
        // total abs weight: 1.0 + 0.5 = 1.5
        // weighted sum: 1.0 + (-0.4) = 0.6
        // score: 0.6 / 1.5 = 0.4
        $result = TrustScoreCalculator::fromClaims($claims, [
            'device.registered' => 1.0,
            'anomaly.detected' => -0.5,
        ]);

        self::assertEqualsWithDelta(0.4, $result->score, 0.001);
    }

    #[Test]
    public function returnsResultType(): void
    {
        $result = TrustScoreCalculator::fromClaims(new ClaimSet(), []);

        self::assertInstanceOf(TrustScoreResult::class, $result);
    }
}
