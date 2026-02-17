<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\I18n\Locale;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\I18nConfig;
use Pulsar\I18n\Locale\RouteBasedLocaleUrlResolver;
use Pulsar\I18n\Locale\UrlPrefixExtractor;

#[CoversClass(RouteBasedLocaleUrlResolver::class)]
final class RouteBasedLocaleUrlResolverTest extends TestCase
{
    #[Test]
    public function resolves_alternates_for_all_supported_locales(): void
    {
        $extractor = new UrlPrefixExtractor();
        $config = $this->buildConfig(
            defaultLocale: 'en',
            supportedLocales: ['en', 'fr', 'de'],
            defaultLocaleInUrl: false,
        );

        $resolver = new RouteBasedLocaleUrlResolver($extractor, $config);

        $alternates = $resolver->resolveAlternates('/en/about', 'en');

        self::assertArrayHasKey('en', $alternates);
        self::assertArrayHasKey('fr', $alternates);
        self::assertArrayHasKey('de', $alternates);
    }

    #[Test]
    public function default_locale_omits_prefix_when_not_in_url(): void
    {
        $extractor = new UrlPrefixExtractor();
        $config = $this->buildConfig(
            defaultLocale: 'en',
            supportedLocales: ['en', 'fr'],
            defaultLocaleInUrl: false,
        );

        $resolver = new RouteBasedLocaleUrlResolver($extractor, $config);
        $alternates = $resolver->resolveAlternates('/en/about', 'en');

        // Default locale (en) should have no prefix when defaultLocaleInUrl is false
        self::assertSame('/about', $alternates['en']);
        self::assertSame('/fr/about', $alternates['fr']);
    }

    #[Test]
    public function default_locale_keeps_prefix_when_in_url(): void
    {
        $extractor = new UrlPrefixExtractor();
        $config = $this->buildConfig(
            defaultLocale: 'en',
            supportedLocales: ['en', 'fr'],
            defaultLocaleInUrl: true,
        );

        $resolver = new RouteBasedLocaleUrlResolver($extractor, $config);
        $alternates = $resolver->resolveAlternates('/en/about', 'en');

        self::assertSame('/en/about', $alternates['en']);
        self::assertSame('/fr/about', $alternates['fr']);
    }

    #[Test]
    public function resolves_from_non_default_locale_path(): void
    {
        $extractor = new UrlPrefixExtractor();
        $config = $this->buildConfig(
            defaultLocale: 'en',
            supportedLocales: ['en', 'fr', 'de'],
            defaultLocaleInUrl: false,
        );

        $resolver = new RouteBasedLocaleUrlResolver($extractor, $config);
        $alternates = $resolver->resolveAlternates('/fr/products', 'fr');

        self::assertSame('/products', $alternates['en']);
        self::assertSame('/fr/products', $alternates['fr']);
        self::assertSame('/de/products', $alternates['de']);
    }

    /**
     * @param list<string> $supportedLocales
     */
    private function buildConfig(
        string $defaultLocale,
        array $supportedLocales,
        bool $defaultLocaleInUrl,
    ): I18nConfig {
        return new I18nConfig(
            defaultLocale: $defaultLocale,
            supportedLocales: $supportedLocales,
            fallbackLocales: [$defaultLocale],
            catalogPath: null,
            regulated: false,
            maxSupportedLocales: 50,
            strictMode: false,
            defaultLocaleInUrl: $defaultLocaleInUrl,
        );
    }
}
