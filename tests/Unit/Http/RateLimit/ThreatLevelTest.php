<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\RateLimit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\RateLimit\ThreatLevel;

use function count;

#[CoversNothing]
final class ThreatLevelTest extends TestCase
{
    #[Test]
    #[DataProvider('threatLevelMultiplierProvider')]
    public function limitMultiplierReturnsCorrectValueForEachLevel(ThreatLevel $level, float $expected): void
    {
        self::assertSame($expected, $level->limitMultiplier());
    }

    /**
     * @return iterable<string, array{ThreatLevel, float}>
     */
    public static function threatLevelMultiplierProvider(): iterable
    {
        yield 'Normal keeps full capacity' => [ThreatLevel::Normal, 1.0];
        yield 'Elevated reduces to 75%' => [ThreatLevel::Elevated, 0.75];
        yield 'High reduces to 50%' => [ThreatLevel::High, 0.50];
        yield 'Critical reduces to 25%' => [ThreatLevel::Critical, 0.25];
    }

    #[Test]
    public function backingValuesAreStrings(): void
    {
        self::assertSame('normal', ThreatLevel::Normal->value);
        self::assertSame('elevated', ThreatLevel::Elevated->value);
        self::assertSame('high', ThreatLevel::High->value);
        self::assertSame('critical', ThreatLevel::Critical->value);
    }

    #[Test]
    public function allCasesAreCovered(): void
    {
        $cases = ThreatLevel::cases();
        self::assertCount(4, $cases);
    }

    #[Test]
    public function multiplierDecreasesWithSeverity(): void
    {
        $multipliers = array_map(
            static fn(ThreatLevel $l): float => $l->limitMultiplier(),
            ThreatLevel::cases(),
        );

        for ($i = 1; $i < count($multipliers); $i++) {
            self::assertLessThan(
                $multipliers[$i - 1],
                $multipliers[$i],
                'Multiplier should decrease as threat level increases',
            );
        }
    }
}
