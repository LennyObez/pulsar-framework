<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core\Wiring;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\I18nConfig;
use Pulsar\Container\Container;
use Pulsar\Core\Wiring\I18nWiring;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\I18n\CatalogInterface;
use Pulsar\I18n\Format\CurrencyFormatterInterface;
use Pulsar\I18n\Format\DateFormatterInterface;
use Pulsar\I18n\Format\MessageFormatterInterface;
use Pulsar\I18n\Format\NumberFormatterInterface;
use Pulsar\I18n\Locale\CookieAwareLocaleNegotiator;
use Pulsar\I18n\Locale\LocaleNegotiator;
use Pulsar\I18n\Locale\LocaleUrlGenerator;
use Pulsar\I18n\Locale\UrlPrefixExtractor;
use Pulsar\I18n\LocaleNegotiatorInterface;
use Pulsar\I18n\Region\CountryRegistry;
use Pulsar\I18n\Translator;
use Pulsar\I18n\TranslatorInterface;
use Pulsar\Routing\Router;
use Pulsar\View\Engine\TemplateLocaleHelper;
use ReflectionMethod;

use function bin2hex;
use function extension_loaded;
use function file_put_contents;
use function mkdir;
use function random_bytes;

#[CoversClass(I18nWiring::class)]
final class I18nWiringTest extends TestCase
{
    #[Test]
    public function wireRegistersI18nServices(): void
    {
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManager();
        $configManager->load();

        $wiring = new I18nWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        self::assertTrue($container->has(I18nConfig::class));
        self::assertTrue($container->has(CatalogInterface::class));
        self::assertTrue($container->has(MessageFormatterInterface::class));
        self::assertTrue($container->has(TranslatorInterface::class));
        self::assertTrue($container->has(Translator::class));
        self::assertTrue($container->has(LocaleNegotiatorInterface::class));
    }

    #[Test]
    public function wiresPlainNegotiatorWhenLocaleCookieDisabled(): void
    {
        $container = new Container();
        $configManager = $this->createConfigManager(); // locale_cookie_enabled defaults false
        $configManager->load();

        new I18nWiring()->wire($container, $configManager, new MiddlewarePipeline($container), new MiddlewareRegistry(), new Router());

        // CookieAwareLocaleNegotiator wraps (does not extend) LocaleNegotiator, so
        // asserting the plain class is sufficient to prove the cookie-aware
        // decorator was not wired.
        self::assertInstanceOf(LocaleNegotiator::class, $container->get(LocaleNegotiatorInterface::class));
    }

    #[Test]
    public function wiresCookieAwareNegotiatorWhenLocaleCookieEnabled(): void
    {
        $container = new Container();
        $configManager = $this->createConfigManagerWithLocaleCookie();
        $configManager->load();

        new I18nWiring()->wire($container, $configManager, new MiddlewarePipeline($container), new MiddlewareRegistry(), new Router());

        self::assertInstanceOf(CookieAwareLocaleNegotiator::class, $container->get(LocaleNegotiatorInterface::class));
    }

    #[Test]
    public function warnsWhenCourtesyFallbackLocaleIsNotSupported(): void
    {
        $container = new Container();
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(self::stringContains('courtesy_fallback_locale'));
        $container->instance(LoggerInterface::class, $logger);

        $configManager = $this->createConfigManagerWithUnsupportedCourtesyFallback();
        $configManager->load();

        new I18nWiring()->wire($container, $configManager, new MiddlewarePipeline($container), new MiddlewareRegistry(), new Router());
    }

    private function createConfigManagerWithUnsupportedCourtesyFallback(): ConfigManager
    {
        $configPath = sys_get_temp_dir() . '/pulsar_i18n_wiring_badfb_' . bin2hex(random_bytes(4));
        @mkdir($configPath, 0o755, true);

        file_put_contents($configPath . '/app.php', '<?php return ["name" => "Test", "env" => "testing", "debug" => false, "timezone" => "UTC", "locale" => "en"];');
        file_put_contents($configPath . '/observability.php', '<?php return ["logging" => ["default_channel" => "file", "level" => "debug", "channels" => []]];');
        file_put_contents($configPath . '/security.php', '<?php return ["session" => [], "csrf" => [], "headers" => [], "rate_limit" => []];');
        // courtesy_fallback_locale 'ja' is NOT in supported_locales -> boot warning.
        file_put_contents($configPath . '/i18n.php', '<?php return ["default_locale" => "en", "supported_locales" => ["en", "fr"], "fallback_locales" => ["en"], "url_strategy" => "path_prefix", "courtesy_redirect" => true, "courtesy_fallback_locale" => "ja"];');

        return new ConfigManager($configPath);
    }

    private function createConfigManagerWithLocaleCookie(): ConfigManager
    {
        $configPath = sys_get_temp_dir() . '/pulsar_i18n_wiring_cookie_' . bin2hex(random_bytes(4));
        @mkdir($configPath, 0o755, true);

        file_put_contents($configPath . '/app.php', '<?php return ["name" => "Test", "env" => "testing", "debug" => false, "timezone" => "UTC", "locale" => "en"];');
        file_put_contents($configPath . '/observability.php', '<?php return ["logging" => ["default_channel" => "file", "level" => "debug", "channels" => []]];');
        file_put_contents($configPath . '/security.php', '<?php return ["session" => [], "csrf" => [], "headers" => [], "rate_limit" => []];');
        file_put_contents($configPath . '/i18n.php', '<?php return ["default_locale" => "en", "supported_locales" => ["en", "fr"], "fallback_locales" => ["en"], "regulated" => false, "locale_cookie_enabled" => true];');

        return new ConfigManager($configPath);
    }

    #[Test]
    public function deriveDefaultCountryUsesLocaleRegionInsteadOfHardcodedUs(): void
    {
        // FR-41: the region/currency default country is derived from the
        // configured default locale — explicit region subtag first (fr-FR / fr_CA),
        // then the bare language code when it is itself a known ISO country (fr →
        // FR) — instead of always assuming the US.
        $wiring = new I18nWiring();
        $registry = new CountryRegistry();
        $derive = new ReflectionMethod($wiring, 'deriveDefaultCountry');

        self::assertSame('FR', $derive->invoke($wiring, 'fr-FR', $registry));
        self::assertSame('CA', $derive->invoke($wiring, 'fr_CA', $registry));
        self::assertSame('FR', $derive->invoke($wiring, 'fr', $registry));
        // An unknown derived code still falls back to the US default.
        self::assertSame('US', $derive->invoke($wiring, 'xx', $registry));
    }

    #[Test]
    public function wireRegistersIntlFormattersWhenExtensionAvailable(): void
    {
        if (!extension_loaded('intl')) {
            self::markTestSkipped('ext-intl is not available');
        }

        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManager();
        $configManager->load();

        $wiring = new I18nWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        self::assertTrue($container->has(NumberFormatterInterface::class));
        self::assertTrue($container->has(DateFormatterInterface::class));
        self::assertTrue($container->has(CurrencyFormatterInterface::class));
    }

    #[Test]
    public function wireSkipsWhenNoConfig(): void
    {
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManagerWithout();
        $configManager->load();

        $wiring = new I18nWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        self::assertFalse($container->has(I18nConfig::class));
    }

    #[Test]
    public function wireUsesPathPrefixUrlStrategy(): void
    {
        if (!extension_loaded('intl')) {
            self::markTestSkipped('ext-intl is not available');
        }

        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManagerWithPathPrefix();
        $configManager->load();

        $wiring = new I18nWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        self::assertTrue($container->has(UrlPrefixExtractor::class));
        self::assertTrue($container->has(LocaleUrlGenerator::class));
        self::assertTrue($container->has(TemplateLocaleHelper::class));
    }

    private function createConfigManager(): ConfigManager
    {
        $configPath = sys_get_temp_dir() . '/pulsar_i18n_wiring_' . bin2hex(random_bytes(4));
        @mkdir($configPath, 0o755, true);

        file_put_contents($configPath . '/app.php', '<?php return ["name" => "Test", "env" => "testing", "debug" => false, "timezone" => "UTC", "locale" => "en"];');
        file_put_contents($configPath . '/observability.php', '<?php return ["logging" => ["default_channel" => "file", "level" => "debug", "channels" => []]];');
        file_put_contents($configPath . '/security.php', '<?php return ["session" => [], "csrf" => [], "headers" => [], "rate_limit" => []];');
        file_put_contents($configPath . '/i18n.php', '<?php return ["default_locale" => "en", "supported_locales" => ["en", "fr"], "fallback_locales" => ["en"], "regulated" => false];');

        return new ConfigManager($configPath);
    }

    private function createConfigManagerWithout(): ConfigManager
    {
        $configPath = sys_get_temp_dir() . '/pulsar_i18n_wiring_no_' . bin2hex(random_bytes(4));
        @mkdir($configPath, 0o755, true);

        file_put_contents($configPath . '/app.php', '<?php return ["name" => "Test", "env" => "testing", "debug" => false, "timezone" => "UTC", "locale" => "en"];');
        file_put_contents($configPath . '/observability.php', '<?php return ["logging" => ["default_channel" => "file", "level" => "debug", "channels" => []]];');
        file_put_contents($configPath . '/security.php', '<?php return ["session" => [], "csrf" => [], "headers" => [], "rate_limit" => []];');

        return new ConfigManager($configPath);
    }

    private function createConfigManagerWithPathPrefix(): ConfigManager
    {
        $configPath = sys_get_temp_dir() . '/pulsar_i18n_wiring_prefix_' . bin2hex(random_bytes(4));
        @mkdir($configPath, 0o755, true);

        file_put_contents($configPath . '/app.php', '<?php return ["name" => "Test", "env" => "testing", "debug" => false, "timezone" => "UTC", "locale" => "en"];');
        file_put_contents($configPath . '/observability.php', '<?php return ["logging" => ["default_channel" => "file", "level" => "debug", "channels" => []]];');
        file_put_contents($configPath . '/security.php', '<?php return ["session" => [], "csrf" => [], "headers" => [], "rate_limit" => []];');
        file_put_contents($configPath . '/i18n.php', '<?php return ["default_locale" => "en", "supported_locales" => ["en", "fr", "de"], "fallback_locales" => ["en"], "regulated" => false, "url_strategy" => "path_prefix"];');

        return new ConfigManager($configPath);
    }
}
