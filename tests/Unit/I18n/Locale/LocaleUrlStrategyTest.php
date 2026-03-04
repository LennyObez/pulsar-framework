<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\I18n\Locale;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\I18n\Locale\LocaleUrlStrategy;

#[CoversClass(LocaleUrlStrategy::class)]
final class LocaleUrlStrategyTest extends TestCase
{
    #[Test]
    public function all_cases_exist(): void
    {
        $cases = LocaleUrlStrategy::cases();

        self::assertCount(2, $cases);
    }

    #[Test]
    #[DataProvider('caseValueProvider')]
    public function backed_values(string $expected, LocaleUrlStrategy $case): void
    {
        self::assertSame($expected, $case->value);
    }

    /**
     * @return iterable<string, array{string, LocaleUrlStrategy}>
     */
    public static function caseValueProvider(): iterable
    {
        yield 'None' => ['none', LocaleUrlStrategy::None];
        yield 'PathPrefix' => ['path_prefix', LocaleUrlStrategy::PathPrefix];
    }

    #[Test]
    public function try_from_valid(): void
    {
        self::assertSame(LocaleUrlStrategy::PathPrefix, LocaleUrlStrategy::tryFrom('path_prefix'));
    }

    #[Test]
    public function try_from_invalid_returns_null(): void
    {
        self::assertNull(LocaleUrlStrategy::tryFrom('subdomain'));
    }
}
