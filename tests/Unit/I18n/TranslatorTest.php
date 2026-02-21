<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\I18n;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\I18nConfig;
use Pulsar\I18n\CatalogInterface;
use Pulsar\I18n\Exception\I18nException;
use Pulsar\I18n\Exception\MissingTranslationException;
use Pulsar\I18n\Format\MessageFormatterInterface;
use Pulsar\I18n\TranslationEntry;
use Pulsar\I18n\Translator;

#[CoversClass(Translator::class)]
final class TranslatorTest extends TestCase
{
    protected function tearDown(): void
    {
        Translator::resetGlobalInstance();
    }

    #[Test]
    public function translateReturnsMessageFromCatalog(): void
    {
        $catalog = $this->createStub(CatalogInterface::class);
        $catalog->method('get')->willReturn(new TranslationEntry(key: 'welcome', message: 'Hello!'));

        $translator = new Translator($catalog, $this->makeConfig());

        self::assertSame('Hello!', $translator->translate('welcome'));
    }

    #[Test]
    public function translateReturnsKeyWhenNotFoundInNonStrictMode(): void
    {
        $catalog = $this->createStub(CatalogInterface::class);
        $catalog->method('get')->willReturn(null);

        $translator = new Translator($catalog, $this->makeConfig());

        self::assertSame('missing.key', $translator->translate('missing.key'));
    }

    #[Test]
    public function translateThrowsInStrictMode(): void
    {
        $catalog = $this->createStub(CatalogInterface::class);
        $catalog->method('get')->willReturn(null);

        $config = $this->makeConfig(strictMode: true);
        $translator = new Translator($catalog, $config);

        $this->expectException(MissingTranslationException::class);
        $translator->translate('missing.key');
    }

    #[Test]
    public function translateUsesFormatter(): void
    {
        $catalog = $this->createStub(CatalogInterface::class);
        $catalog->method('get')->willReturn(new TranslationEntry(key: 'greeting', message: 'Hello, {name}!'));

        $formatter = $this->createStub(MessageFormatterInterface::class);
        $formatter->method('format')->willReturn('Hello, World!');

        $translator = new Translator($catalog, $this->makeConfig(), $formatter);

        self::assertSame('Hello, World!', $translator->translate('greeting', ['name' => 'World']));
    }

    #[Test]
    public function translateSkipsFormatterWithNoParameters(): void
    {
        $catalog = $this->createStub(CatalogInterface::class);
        $catalog->method('get')->willReturn(new TranslationEntry(key: 'welcome', message: 'Hello!'));

        $formatter = $this->createMock(MessageFormatterInterface::class);
        $formatter->expects(self::never())->method('format');

        $translator = new Translator($catalog, $this->makeConfig(), $formatter);

        self::assertSame('Hello!', $translator->translate('welcome'));
    }

    #[Test]
    public function translateFallsBackToLanguagePrefix(): void
    {
        $catalog = $this->createStub(CatalogInterface::class);
        $catalog->method('get')->willReturnCallback(
            static function (string $key, string $locale): ?TranslationEntry {
                if ($locale === 'fr') {
                    return new TranslationEntry(key: $key, message: 'Bonjour!');
                }
                return null;
            },
        );

        $translator = new Translator($catalog, $this->makeConfig());

        self::assertSame('Bonjour!', $translator->translate('welcome', locale: 'fr_CA'));
    }

    #[Test]
    public function translateFallsBackToConfigFallbackLocales(): void
    {
        $catalog = $this->createStub(CatalogInterface::class);
        $catalog->method('get')->willReturnCallback(
            static function (string $key, string $locale): ?TranslationEntry {
                if ($locale === 'en') {
                    return new TranslationEntry(key: $key, message: 'Hello!');
                }
                return null;
            },
        );

        $config = $this->makeConfig(fallbackLocales: ['en']);
        $translator = new Translator($catalog, $config);

        self::assertSame('Hello!', $translator->translate('welcome', locale: 'de'));
    }

    #[Test]
    public function getAndSetLocale(): void
    {
        $catalog = $this->createStub(CatalogInterface::class);

        $translator = new Translator($catalog, $this->makeConfig());

        self::assertSame('en', $translator->locale);

        $translator->locale = 'fr';

        self::assertSame('fr', $translator->locale);
    }

    #[Test]
    public function hasReturnsTrueForExistingKey(): void
    {
        $catalog = $this->createStub(CatalogInterface::class);
        $catalog->method('has')->willReturn(true);

        $translator = new Translator($catalog, $this->makeConfig());

        self::assertTrue($translator->has('welcome'));
    }

    #[Test]
    public function hasReturnsFalseForMissingKey(): void
    {
        $catalog = $this->createStub(CatalogInterface::class);
        $catalog->method('has')->willReturn(false);

        $translator = new Translator($catalog, $this->makeConfig());

        self::assertFalse($translator->has('missing'));
    }

    #[Test]
    public function globalInstanceThrowsWhenNotSet(): void
    {
        $this->expectException(I18nException::class);
        Translator::getGlobalInstance();
    }

    #[Test]
    public function globalInstanceSetAndGet(): void
    {
        $catalog = $this->createStub(CatalogInterface::class);
        $translator = new Translator($catalog, $this->makeConfig());

        Translator::setGlobalInstance($translator);

        self::assertSame($translator, Translator::getGlobalInstance());
    }

    #[Test]
    public function translateUsesDomainParameter(): void
    {
        $catalog = $this->createStub(CatalogInterface::class);
        $catalog->method('get')->willReturnCallback(
            static function (string $key, string $locale, string $domain): ?TranslationEntry {
                if ($domain === 'errors') {
                    return new TranslationEntry(key: $key, message: 'Error occurred');
                }
                return null;
            },
        );

        $translator = new Translator($catalog, $this->makeConfig());

        self::assertSame('Error occurred', $translator->translate('error.generic', domain: 'errors'));
    }

    #[Test]
    public function translateReplacesColonPlaceholders(): void
    {
        $catalog = $this->createStub(CatalogInterface::class);
        $catalog->method('get')->willReturn(
            new TranslationEntry(key: 'copyright', message: '© :year Author'),
        );

        $translator = new Translator($catalog, $this->makeConfig());

        self::assertSame('© 2026 Author', $translator->translate('copyright', ['year' => 2026]));
    }

    #[Test]
    public function translateSplitsDotNotationIntoDomainAndKey(): void
    {
        $catalog = $this->createStub(CatalogInterface::class);
        $catalog->method('get')->willReturnCallback(
            static function (string $key, string $locale, string $domain): ?TranslationEntry {
                if ($domain === 'navigation' && $key === 'home') {
                    return new TranslationEntry(key: 'home', message: 'Home');
                }

                return null;
            },
        );

        $translator = new Translator($catalog, $this->makeConfig());

        // "navigation.home" should split to domain=navigation, key=home
        self::assertSame('Home', $translator->translate('navigation.home'));
    }

    #[Test]
    public function translateDotNotationFallsBackToLiteralKey(): void
    {
        $callLog = [];
        $catalog = $this->createStub(CatalogInterface::class);
        $catalog->method('get')->willReturnCallback(
            static function (string $key, string $locale, string $domain) use (&$callLog): ?TranslationEntry {
                $callLog[] = "$domain.$key";
                // Only match the literal key "config.app.name" in default domain
                if ($domain === 'messages' && $key === 'config.app.name') {
                    return new TranslationEntry(key: 'config.app.name', message: 'My App');
                }

                return null;
            },
        );

        $translator = new Translator($catalog, $this->makeConfig());

        // "config.app.name" first tries domain=config, key=app.name (miss),
        // then falls back to literal key "config.app.name" in domain=messages (hit)
        self::assertSame('My App', $translator->translate('config.app.name'));
    }

    /**
     * @param list<string> $fallbackLocales
     */
    private function makeConfig(
        string $defaultLocale = 'en',
        array $fallbackLocales = ['en'],
        bool $strictMode = false,
    ): I18nConfig {
        return new I18nConfig(
            defaultLocale: $defaultLocale,
            supportedLocales: ['en', 'fr', 'de'],
            fallbackLocales: $fallbackLocales,
            catalogPath: null,
            regulated: false,
            maxSupportedLocales: 50,
            strictMode: $strictMode,
        );
    }
}
