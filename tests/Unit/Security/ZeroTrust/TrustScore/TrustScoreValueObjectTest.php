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
use Pulsar\Security\ZeroTrust\TrustScore\ScoreExplanation;
use Pulsar\Security\ZeroTrust\TrustScore\TrustScoreResult;

#[CoversClass(ScoreExplanation::class)]
#[CoversClass(TrustScoreResult::class)]
final class TrustScoreValueObjectTest extends TestCase
{
    // ── ScoreExplanation ────────────────────────────────────────────────

    #[Test]
    public function scoreExplanationStoresProperties(): void
    {
        $explanation = new ScoreExplanation(
            claimName: 'device.registered',
            weight: 0.4,
            claimConfidence: 0.9,
            contribution: 0.36,
        );

        self::assertSame('device.registered', $explanation->claimName);
        self::assertSame(0.4, $explanation->weight);
        self::assertSame(0.9, $explanation->claimConfidence);
        self::assertSame(0.36, $explanation->contribution);
    }

    #[Test]
    public function scoreExplanationContributionCanBeZero(): void
    {
        $explanation = new ScoreExplanation(
            claimName: 'ip.in_range',
            weight: 0.0,
            claimConfidence: 1.0,
            contribution: 0.0,
        );

        self::assertSame(0.0, $explanation->contribution);
        self::assertSame(0.0, $explanation->weight);
    }

    // ── TrustScoreResult ────────────────────────────────────────────────

    #[Test]
    public function trustScoreResultStoresProperties(): void
    {
        $claim = new Claim(
            name: 'device.registered',
            value: true,
            source: ClaimSource::DeviceSignal,
            confidence: 0.95,
            timestamp: new DateTimeImmutable(),
        );
        $claimSet = new ClaimSet([$claim]);

        $explanation = new ScoreExplanation(
            claimName: 'device.registered',
            weight: 0.5,
            claimConfidence: 0.95,
            contribution: 0.475,
        );

        $result = new TrustScoreResult(
            score: 0.85,
            explanations: [$explanation],
            claimSnapshot: $claimSet,
        );

        self::assertSame(0.85, $result->score);
        self::assertCount(1, $result->explanations);
        self::assertSame($explanation, $result->explanations[0]);
        self::assertSame($claimSet, $result->claimSnapshot);
    }

    #[Test]
    public function trustScoreResultWithEmptyExplanations(): void
    {
        $result = new TrustScoreResult(
            score: 0.0,
            explanations: [],
            claimSnapshot: new ClaimSet(),
        );

        self::assertSame(0.0, $result->score);
        self::assertCount(0, $result->explanations);
        self::assertCount(0, $result->claimSnapshot);
    }

    #[Test]
    public function trustScoreResultWithMultipleExplanations(): void
    {
        $explanations = [
            new ScoreExplanation('device.registered', 0.4, 1.0, 0.4),
            new ScoreExplanation('ip.in_range', 0.3, 0.8, 0.24),
            new ScoreExplanation('behavior.normal', 0.3, 0.6, 0.18),
        ];

        $result = new TrustScoreResult(
            score: 0.82,
            explanations: $explanations,
            claimSnapshot: new ClaimSet(),
        );

        self::assertCount(3, $result->explanations);
        self::assertSame('ip.in_range', $result->explanations[1]->claimName);
        self::assertSame(0.24, $result->explanations[1]->contribution);
    }
}
