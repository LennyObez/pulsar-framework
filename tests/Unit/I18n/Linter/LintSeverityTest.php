<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\I18n\Linter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\I18n\Linter\LintSeverity;

#[CoversClass(LintSeverity::class)]
final class LintSeverityTest extends TestCase
{
    #[Test]
    public function hasTwoCases(): void
    {
        self::assertCount(2, LintSeverity::cases());
    }

    #[Test]
    #[DataProvider('lintSeverityProvider')]
    public function backedValues(LintSeverity $severity, string $expected): void
    {
        self::assertSame($expected, $severity->value);
    }

    /**
     * @return iterable<string, array{LintSeverity, string}>
     */
    public static function lintSeverityProvider(): iterable
    {
        yield 'Warning' => [LintSeverity::Warning, 'warning'];
        yield 'Error' => [LintSeverity::Error, 'error'];
    }

    #[Test]
    public function fromBackedValue(): void
    {
        self::assertSame(LintSeverity::Error, LintSeverity::from('error'));
    }
}
