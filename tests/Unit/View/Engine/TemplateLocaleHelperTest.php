<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Engine;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\I18nConfig;
use Pulsar\I18n\Locale\LocaleUrlGenerator;
use Pulsar\I18n\Locale\LocaleUrlStrategy;
use Pulsar\I18n\Locale\UrlPrefixExtractor;
use Pulsar\I18n\TranslatorInterface;
use Pulsar\View\Engine\TemplateLocaleHelper;

#[CoversClass(TemplateLocaleHelper::class)]
final class TemplateLocaleHelperTest extends TestCase
{
    private function makeTranslator(string $locale): TranslatorInterface
    {
        return new class ($locale) implements TranslatorInterface {
            public string $locale;

            public function __construct(string $locale)
            {
                $this->locale = $locale;
            }

            public function translate(string $key, array $parameters = [], ?string $locale = null, string $domain = 'messages'): string
            {
                return $key;
            }

            public function has(string $key, ?string $locale = null, string $domain = 'messages'): bool
            {
                return false;
            }
        };
    }

    #[Test]
    public function currentReturnsCurrentLocale(): void
    {
        $helper = new TemplateLocaleHelper(translator: $this->makeTranslator('fr'));

        self::assertSame('fr', $helper->current());
    }

    #[Test]
    public function currentReflectsLocaleChangesOnTranslator(): void
    {
        $translator = $this->makeTranslator('en');
        $helper = new TemplateLocaleHelper(translator: $translator);

        self::assertSame('en', $helper->current());

        $translator->locale = 'fr';

        self::assertSame('fr', $helper->current());
    }

    #[Test]
    public function isRtlReturnsTrueForArabic(): void
    {
        $helper = new TemplateLocaleHelper(translator: $this->makeTranslator('ar'));

        self::assertTrue($helper->isRtl());
    }

    #[Test]
    public function isRtlReturnsFalseForFrench(): void
    {
        $helper = new TemplateLocaleHelper(translator: $this->makeTranslator('fr'));

        self::assertFalse($helper->isRtl());
    }

    #[Test]
    public function isActiveReturnsTrueForMatchingLocale(): void
    {
        $helper = new TemplateLocaleHelper(translator: $this->makeTranslator('de'));

        self::assertTrue($helper->isActive('de'));
        self::assertFalse($helper->isActive('en'));
    }

    #[Test]
    public function isActiveReflectsLocaleChanges(): void
    {
        $translator = $this->makeTranslator('en');
        $helper = new TemplateLocaleHelper(translator: $translator);

        self::assertTrue($helper->isActive('en'));

        $translator->locale = 'de';

        self::assertFalse($helper->isActive('en'));
        self::assertTrue($helper->isActive('de'));
    }

    #[Test]
    public function switchUrlsReturnsAlternatesFromGenerator(): void
    {
        $config = new I18nConfig(
            defaultLocale: 'en',
            supportedLocales: ['en', 'fr'],
            fallbackLocales: ['en'],
            catalogPath: null,
            regulated: false,
            maxSupportedLocales: 50,
            strictMode: false,
            urlStrategy: LocaleUrlStrategy::PathPrefix,
        );

        $generator = new LocaleUrlGenerator(
            extractor: new UrlPrefixExtractor(),
            config: $config,
        );

        $helper = new TemplateLocaleHelper(
            translator: $this->makeTranslator('en'),
            urlGenerator: $generator,
        );

        $urls = $helper->switchUrls('/about');

        self::assertSame([
            'en' => '/about',
            'fr' => '/fr/about',
        ], $urls);
    }

    #[Test]
    public function switchUrlsReturnsEmptyWhenNoGenerator(): void
    {
        $helper = new TemplateLocaleHelper(translator: $this->makeTranslator('en'));

        self::assertSame([], $helper->switchUrls('/about'));
    }

    #[Test]
    public function isRtlReflectsLocaleChanges(): void
    {
        $translator = $this->makeTranslator('en');
        $helper = new TemplateLocaleHelper(translator: $translator);

        self::assertFalse($helper->isRtl());

        $translator->locale = 'ar';

        self::assertTrue($helper->isRtl());
    }

    #[Test]
    public function switchUrlsReflectsLocaleChanges(): void
    {
        $config = new I18nConfig(
            defaultLocale: 'en',
            supportedLocales: ['en', 'fr'],
            fallbackLocales: ['en'],
            catalogPath: null,
            regulated: false,
            maxSupportedLocales: 50,
            strictMode: false,
            urlStrategy: LocaleUrlStrategy::PathPrefix,
        );

        $generator = new LocaleUrlGenerator(
            extractor: new UrlPrefixExtractor(),
            config: $config,
        );

        $translator = $this->makeTranslator('en');
        $helper = new TemplateLocaleHelper(
            translator: $translator,
            urlGenerator: $generator,
        );

        // Switch locale mid-request — helper reads live translator state
        $translator->locale = 'fr';

        $urls = $helper->switchUrls('/about');

        // switchUrls passes the current locale to alternates()
        self::assertSame([
            'en' => '/about',
            'fr' => '/fr/about',
        ], $urls);
        self::assertSame('fr', $helper->current());
    }
}
