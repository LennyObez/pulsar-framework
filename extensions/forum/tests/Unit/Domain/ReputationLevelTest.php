<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Forum\Domain\ReputationLevel;

final class ReputationLevelTest extends TestCase
{
    #[Test]
    public function fromScoreReturnsNewcomerForZero(): void
    {
        self::assertSame(ReputationLevel::Newcomer, ReputationLevel::fromScore(0));
    }

    #[Test]
    public function fromScoreReturnsCorrectLevelAtBoundary(): void
    {
        self::assertSame(ReputationLevel::Contributor, ReputationLevel::fromScore(10));
        self::assertSame(ReputationLevel::Regular, ReputationLevel::fromScore(50));
        self::assertSame(ReputationLevel::Trusted, ReputationLevel::fromScore(100));
        self::assertSame(ReputationLevel::Veteran, ReputationLevel::fromScore(250));
        self::assertSame(ReputationLevel::Expert, ReputationLevel::fromScore(500));
        self::assertSame(ReputationLevel::Champion, ReputationLevel::fromScore(1000));
    }

    #[Test]
    public function fromScoreReturnsCorrectLevelBetweenBoundaries(): void
    {
        self::assertSame(ReputationLevel::Newcomer, ReputationLevel::fromScore(5));
        self::assertSame(ReputationLevel::Contributor, ReputationLevel::fromScore(25));
        self::assertSame(ReputationLevel::Regular, ReputationLevel::fromScore(75));
        self::assertSame(ReputationLevel::Champion, ReputationLevel::fromScore(5000));
    }

    #[Test]
    public function fromScoreHandlesNegative(): void
    {
        self::assertSame(ReputationLevel::Newcomer, ReputationLevel::fromScore(-10));
    }

    #[Test]
    public function labelReturnsHumanReadable(): void
    {
        self::assertSame('Newcomer', ReputationLevel::Newcomer->label());
        self::assertSame('Contributor', ReputationLevel::Contributor->label());
        self::assertSame('Regular', ReputationLevel::Regular->label());
        self::assertSame('Trusted', ReputationLevel::Trusted->label());
        self::assertSame('Veteran', ReputationLevel::Veteran->label());
        self::assertSame('Expert', ReputationLevel::Expert->label());
        self::assertSame('Champion', ReputationLevel::Champion->label());
    }

    #[Test]
    public function minimumScoreMatchesBackingValue(): void
    {
        self::assertSame(0, ReputationLevel::Newcomer->minimumScore());
        self::assertSame(10, ReputationLevel::Contributor->minimumScore());
        self::assertSame(1000, ReputationLevel::Champion->minimumScore());
    }
}
