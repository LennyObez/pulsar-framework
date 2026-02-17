<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\I18n\Locale;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\I18n\Locale\HreflangLink;

#[CoversClass(HreflangLink::class)]
final class HreflangLinkTest extends TestCase
{
    #[Test]
    public function stores_locale_and_href(): void
    {
        $link = new HreflangLink(locale: 'fr', href: '/fr/about');

        self::assertSame('fr', $link->locale);
        self::assertSame('/fr/about', $link->href);
    }

    #[Test]
    #[DataProvider('linkProvider')]
    public function various_locale_and_href_combinations(string $locale, string $href): void
    {
        $link = new HreflangLink(locale: $locale, href: $href);

        self::assertSame($locale, $link->locale);
        self::assertSame($href, $link->href);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function linkProvider(): iterable
    {
        yield 'simple locale' => ['en', '/en/home'];
        yield 'regional locale' => ['pt-BR', '/pt-BR/about'];
        yield 'root path' => ['de', '/de'];
        yield 'absolute URL' => ['ja', 'https://example.com/ja/page'];
        yield 'x-default' => ['x-default', 'https://example.com/'];
    }
}
