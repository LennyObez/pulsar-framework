<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\Environment;
use Pulsar\Config\I18nConfig;

#[CoversClass(I18nConfig::class)]
final class I18nConfigTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('APP_LOCALE');
        putenv('I18N_REGULATED');
    }

    protected function tearDown(): void
    {
        putenv('APP_LOCALE');
        putenv('I18N_REGULATED');
    }

    #[Test]
    public function appliesDefaults(): void
    {
        $env = Environment::load();

        $config = I18nConfig::fromArray([], $env);

        self::assertSame('en', $config->defaultLocale);
        self::assertSame(['en'], $config->supportedLocales);
        self::assertSame(['en'], $config->fallbackLocales);
        self::assertNull($config->catalogPath);
        self::assertFalse($config->regulated);
        self::assertSame(50, $config->maxSupportedLocales);
        self::assertFalse($config->strictMode);
        // Locale-cookie/courtesy features default OFF (backward-compatible).
        self::assertFalse($config->courtesyRedirect);
        self::assertSame('', $config->courtesyFallbackLocale);
        self::assertFalse($config->localeCookieEnabled);
        self::assertSame('pulsar_locale', $config->localeCookieName);
    }

    #[Test]
    public function constructsLocaleCookieAndCourtesyFromArray(): void
    {
        $config = I18nConfig::fromArray([
            'courtesy_redirect' => true,
            'courtesy_fallback_locale' => 'en',
            'locale_cookie_enabled' => true,
            'locale_cookie_name' => 'lang',
        ], Environment::load());

        self::assertTrue($config->courtesyRedirect);
        self::assertSame('en', $config->courtesyFallbackLocale);
        self::assertTrue($config->localeCookieEnabled);
        self::assertSame('lang', $config->localeCookieName);
    }

    #[Test]
    public function constructsFromArray(): void
    {
        $env = Environment::load();

        $config = I18nConfig::fromArray([
            'default_locale' => 'fr',
            'supported_locales' => ['en', 'fr', 'de'],
            'fallback_locales' => ['en', 'fr'],
            'catalog_path' => '/app/translations',
            'regulated' => true,
            'max_supported_locales' => 100,
            'strict_mode' => true,
        ], $env);

        self::assertSame('fr', $config->defaultLocale);
        self::assertSame(['en', 'fr', 'de'], $config->supportedLocales);
        self::assertSame(['en', 'fr'], $config->fallbackLocales);
        self::assertSame('/app/translations', $config->catalogPath);
        self::assertTrue($config->regulated);
        self::assertSame(100, $config->maxSupportedLocales);
        self::assertTrue($config->strictMode);
    }

    #[Test]
    public function envVarOverridesDefaultLocale(): void
    {
        putenv('APP_LOCALE=ja');
        $env = Environment::load();

        $config = I18nConfig::fromArray(['default_locale' => 'fr'], $env);

        self::assertSame('ja', $config->defaultLocale);
    }

    #[Test]
    public function envVarOverridesRegulated(): void
    {
        putenv('I18N_REGULATED=true');
        $env = Environment::load();

        $config = I18nConfig::fromArray(['regulated' => false], $env);

        self::assertTrue($config->regulated);
    }

    #[Test]
    public function envVarRegulatedFalseOverridesFileTrue(): void
    {
        putenv('I18N_REGULATED=false');
        $env = Environment::load();

        $config = I18nConfig::fromArray(['regulated' => true], $env);

        self::assertFalse($config->regulated);
    }

    #[Test]
    public function supportedLocalesFiltersNonStrings(): void
    {
        $env = Environment::load();

        $config = I18nConfig::fromArray([
            'supported_locales' => ['en', 42, 'fr', null, 'de'],
        ], $env);

        self::assertSame(['en', 'fr', 'de'], $config->supportedLocales);
    }

    #[Test]
    public function emptySupportedLocalesFallsBackToDefault(): void
    {
        $env = Environment::load();

        $config = I18nConfig::fromArray([
            'supported_locales' => [],
        ], $env);

        self::assertSame(['en'], $config->supportedLocales);
    }

    #[Test]
    public function maxSupportedLocalesDefaultsOnInvalidType(): void
    {
        $env = Environment::load();

        $config = I18nConfig::fromArray([
            'max_supported_locales' => 'invalid',
        ], $env);

        self::assertSame(50, $config->maxSupportedLocales);
    }

    #[Test]
    public function catalogPathNullByDefault(): void
    {
        $env = Environment::load();

        $config = I18nConfig::fromArray([], $env);

        self::assertNull($config->catalogPath);
    }

    #[Test]
    public function catalogPathIgnoresNonString(): void
    {
        $env = Environment::load();

        $config = I18nConfig::fromArray([
            'catalog_path' => 123,
        ], $env);

        self::assertNull($config->catalogPath);
    }
}
