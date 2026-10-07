<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Forum\Domain;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Forum\Domain\ReputationLevel;

#[CoversNothing]
final class ReputationLevelTest extends TestCase
{
    /** @return iterable<string, array{int, ReputationLevel}> */
    public static function scoreToLevelProvider(): iterable
    {
        if (!enum_exists(ReputationLevel::class)) {
            return;
        }

        yield 'negative score' => [-10, ReputationLevel::Newcomer];
        yield 'zero' => [0, ReputationLevel::Newcomer];
        yield 'just below contributor' => [9, ReputationLevel::Newcomer];
        yield 'exact contributor' => [10, ReputationLevel::Contributor];
        yield 'between contributor and regular' => [25, ReputationLevel::Contributor];
        yield 'exact regular' => [50, ReputationLevel::Regular];
        yield 'exact trusted' => [100, ReputationLevel::Trusted];
        yield 'exact veteran' => [250, ReputationLevel::Veteran];
        yield 'exact expert' => [500, ReputationLevel::Expert];
        yield 'exact champion' => [1000, ReputationLevel::Champion];
        yield 'well above champion' => [5000, ReputationLevel::Champion];
    }

    #[Test]
    #[DataProvider('scoreToLevelProvider')]
    public function fromScoreReturnsCorrectLevel(int $score, ReputationLevel $expected): void
    {
        self::assertSame($expected, ReputationLevel::fromScore($score));
    }

    #[Test]
    public function labelsAreHumanReadable(): void
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
        self::assertSame(50, ReputationLevel::Regular->minimumScore());
        self::assertSame(100, ReputationLevel::Trusted->minimumScore());
        self::assertSame(250, ReputationLevel::Veteran->minimumScore());
        self::assertSame(500, ReputationLevel::Expert->minimumScore());
        self::assertSame(1000, ReputationLevel::Champion->minimumScore());
    }
}
