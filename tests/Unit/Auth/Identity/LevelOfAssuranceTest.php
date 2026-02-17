<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Auth\Identity;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\Identity\LevelOfAssurance;

#[CoversClass(LevelOfAssurance::class)]
final class LevelOfAssuranceTest extends TestCase
{
    #[Test]
    public function lowHasStringValueLow(): void
    {
        self::assertSame('low', LevelOfAssurance::Low->value);
    }

    #[Test]
    public function substantialHasStringValueSubstantial(): void
    {
        self::assertSame('substantial', LevelOfAssurance::Substantial->value);
    }

    #[Test]
    public function highHasStringValueHigh(): void
    {
        self::assertSame('high', LevelOfAssurance::High->value);
    }

    #[Test]
    #[DataProvider('satisfiesProvider')]
    public function satisfiesReturnsExpectedResult(
        LevelOfAssurance $current,
        LevelOfAssurance $required,
        bool $expected,
    ): void {
        self::assertSame($expected, $current->satisfies($required));
    }

    /**
     * @return iterable<string, array{LevelOfAssurance, LevelOfAssurance, bool}>
     */
    public static function satisfiesProvider(): iterable
    {
        yield 'low satisfies low' => [LevelOfAssurance::Low, LevelOfAssurance::Low, true];
        yield 'low does not satisfy substantial' => [LevelOfAssurance::Low, LevelOfAssurance::Substantial, false];
        yield 'low does not satisfy high' => [LevelOfAssurance::Low, LevelOfAssurance::High, false];

        yield 'substantial satisfies low' => [LevelOfAssurance::Substantial, LevelOfAssurance::Low, true];
        yield 'substantial satisfies substantial' => [LevelOfAssurance::Substantial, LevelOfAssurance::Substantial, true];
        yield 'substantial does not satisfy high' => [LevelOfAssurance::Substantial, LevelOfAssurance::High, false];

        yield 'high satisfies low' => [LevelOfAssurance::High, LevelOfAssurance::Low, true];
        yield 'high satisfies substantial' => [LevelOfAssurance::High, LevelOfAssurance::Substantial, true];
        yield 'high satisfies high' => [LevelOfAssurance::High, LevelOfAssurance::High, true];
    }

    #[Test]
    public function canBeCreatedFromString(): void
    {
        self::assertSame(LevelOfAssurance::Low, LevelOfAssurance::from('low'));
        self::assertSame(LevelOfAssurance::Substantial, LevelOfAssurance::from('substantial'));
        self::assertSame(LevelOfAssurance::High, LevelOfAssurance::from('high'));
    }

    #[Test]
    public function tryFromReturnsNullForUnknown(): void
    {
        self::assertNull(LevelOfAssurance::tryFrom('unknown'));
    }

    #[Test]
    public function casesReturnsAllThreeLevels(): void
    {
        self::assertCount(3, LevelOfAssurance::cases());
    }
}
