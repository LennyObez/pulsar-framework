<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\I18n\Locale;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\I18nConfig;
use Pulsar\I18n\Locale\HreflangLink;
use Pulsar\I18n\Locale\LocaleUrlGenerator;
use Pulsar\I18n\Locale\LocaleUrlResolverInterface;
use Pulsar\I18n\Locale\UrlPrefixExtractor;

#[CoversClass(LocaleUrlGenerator::class)]
final class LocaleUrlGeneratorTest extends TestCase
{
    private function createConfig(
        string $defaultLocale = 'en',
        bool $defaultLocaleInUrl = false,
    ): I18nConfig {
        return new I18nConfig(
            defaultLocale: $defaultLocale,
            supportedLocales: ['en', 'fr', 'de'],
            fallbackLocales: ['en'],
            catalogPath: null,
            regulated: false,
            maxSupportedLocales: 50,
            strictMode: false,
            defaultLocaleInUrl: $defaultLocaleInUrl,
        );
    }

    private function createGenerator(?I18nConfig $config = null, ?LocaleUrlResolverInterface $resolver = null): LocaleUrlGenerator
    {
        return new LocaleUrlGenerator(
            new UrlPrefixExtractor(),
            $config ?? $this->createConfig(),
            $resolver,
        );
    }

    #[Test]
    public function urlPrefixesWithLocale(): void
    {
        $gen = $this->createGenerator();

        self::assertSame('/fr/docs', $gen->url('/docs', 'fr'));
    }

    #[Test]
    public function urlUsesDefaultLocaleWhenNoneSpecified(): void
    {
        $gen = $this->createGenerator();

        // Default locale (en) without defaultLocaleInUrl → no prefix
        self::assertSame('/docs', $gen->url('/docs'));
    }

    #[Test]
    public function urlIncludesDefaultLocaleWhenConfigured(): void
    {
        $config = $this->createConfig(defaultLocaleInUrl: true);
        $gen = $this->createGenerator($config);

        self::assertSame('/en/docs', $gen->url('/docs'));
    }

    #[Test]
    public function alternatesReturnsAllSupportedLocales(): void
    {
        $gen = $this->createGenerator();

        $alternates = $gen->alternates('/docs', 'en');

        self::assertCount(3, $alternates);
        self::assertArrayHasKey('en', $alternates);
        self::assertArrayHasKey('fr', $alternates);
        self::assertArrayHasKey('de', $alternates);
    }

    #[Test]
    public function alternatesDelegatesToResolverWhenAvailable(): void
    {
        $resolver = $this->createStub(LocaleUrlResolverInterface::class);
        $resolver->method('resolveAlternates')->willReturn([
            'en' => '/en/custom',
            'fr' => '/fr/custom',
        ]);

        $gen = $this->createGenerator(resolver: $resolver);

        $alternates = $gen->alternates('/page', 'en');

        self::assertSame('/en/custom', $alternates['en']);
        self::assertSame('/fr/custom', $alternates['fr']);
    }

    #[Test]
    public function hreflangLinksIncludesXDefault(): void
    {
        $gen = $this->createGenerator();

        $links = $gen->hreflangLinks('/docs', 'en');

        $locales = array_map(
            static fn(HreflangLink $link): string => $link->locale,
            $links,
        );

        self::assertContains('x-default', $locales);
        self::assertContains('en', $locales);
        self::assertContains('fr', $locales);
        self::assertContains('de', $locales);
    }

    #[Test]
    public function hreflangLinksXDefaultPointsToDefaultLocale(): void
    {
        $gen = $this->createGenerator();

        $links = $gen->hreflangLinks('/docs', 'fr');

        $xDefault = null;
        $enLink = null;

        foreach ($links as $link) {
            if ($link->locale === 'x-default') {
                $xDefault = $link;
            }
            if ($link->locale === 'en') {
                $enLink = $link;
            }
        }

        self::assertNotNull($xDefault);
        self::assertNotNull($enLink);
        self::assertSame($enLink->href, $xDefault->href);
    }

    #[Test]
    public function hreflangLinksCountIsLocalesPlusXDefault(): void
    {
        $gen = $this->createGenerator();

        $links = $gen->hreflangLinks('/page', 'en');

        // 3 locales + 1 x-default = 4
        self::assertCount(4, $links);
    }
}
