<?php

declare(strict_types=1);

namespace Pulsar\Extension\Eidas\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Eidas\Domain\LevelOfAssurance;

final class LevelOfAssuranceTest extends TestCase
{
    #[Test]
    public function allCasesHaveStringValues(): void
    {
        self::assertSame('low', LevelOfAssurance::Low->value);
        self::assertSame('substantial', LevelOfAssurance::Substantial->value);
        self::assertSame('high', LevelOfAssurance::High->value);
    }

    #[Test]
    #[DataProvider('meetsMinimumProvider')]
    public function meetsMinimumReturnsExpectedResult(
        LevelOfAssurance $actual,
        LevelOfAssurance $required,
        bool $expected,
    ): void {
        self::assertSame($expected, $actual->meetsMinimum($required));
    }

    /**
     * @return iterable<string, array{LevelOfAssurance, LevelOfAssurance, bool}>
     */
    public static function meetsMinimumProvider(): iterable
    {
        yield 'low meets low' => [LevelOfAssurance::Low, LevelOfAssurance::Low, true];
        yield 'substantial meets low' => [LevelOfAssurance::Substantial, LevelOfAssurance::Low, true];
        yield 'high meets low' => [LevelOfAssurance::High, LevelOfAssurance::Low, true];
        yield 'low does not meet substantial' => [LevelOfAssurance::Low, LevelOfAssurance::Substantial, false];
        yield 'substantial meets substantial' => [LevelOfAssurance::Substantial, LevelOfAssurance::Substantial, true];
        yield 'high meets substantial' => [LevelOfAssurance::High, LevelOfAssurance::Substantial, true];
        yield 'low does not meet high' => [LevelOfAssurance::Low, LevelOfAssurance::High, false];
        yield 'substantial does not meet high' => [LevelOfAssurance::Substantial, LevelOfAssurance::High, false];
        yield 'high meets high' => [LevelOfAssurance::High, LevelOfAssurance::High, true];
    }
}
