<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Deploy;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Deploy\CheckSeverity;

#[CoversNothing]
final class CheckSeverityTest extends TestCase
{
    #[Test]
    public function hasThreeCases(): void
    {
        self::assertCount(3, CheckSeverity::cases());
    }

    #[Test]
    #[DataProvider('checkSeverityProvider')]
    public function backedValues(CheckSeverity $severity, string $expected): void
    {
        self::assertSame($expected, $severity->value);
    }

    /**
     * @return iterable<string, array{CheckSeverity, string}>
     */
    public static function checkSeverityProvider(): iterable
    {
        yield 'Pass' => [CheckSeverity::Pass, 'pass'];
        yield 'Warning' => [CheckSeverity::Warning, 'warning'];
        yield 'Error' => [CheckSeverity::Error, 'error'];
    }

    #[Test]
    public function isPassingReturnsTrueForPass(): void
    {
        self::assertTrue(CheckSeverity::Pass->isPassing());
    }

    #[Test]
    public function isPassingReturnsFalseForWarningAndError(): void
    {
        self::assertFalse(CheckSeverity::Warning->isPassing());
        self::assertFalse(CheckSeverity::Error->isPassing());
    }

    #[Test]
    public function isFailureReturnsFalseForPass(): void
    {
        self::assertFalse(CheckSeverity::Pass->isFailure());
    }

    #[Test]
    public function isFailureReturnsTrueForWarningAndError(): void
    {
        self::assertTrue(CheckSeverity::Warning->isFailure());
        self::assertTrue(CheckSeverity::Error->isFailure());
    }

    #[Test]
    public function fromBackedValue(): void
    {
        self::assertSame(CheckSeverity::Error, CheckSeverity::from('error'));
    }
}
