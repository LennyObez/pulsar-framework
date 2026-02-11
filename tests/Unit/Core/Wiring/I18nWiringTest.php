<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core\Wiring;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\I18nConfig;
use Pulsar\Container\Container;
use Pulsar\Core\Wiring\I18nWiring;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\I18n\CatalogInterface;
use Pulsar\I18n\Format\MessageFormatterInterface;
use Pulsar\I18n\LocaleNegotiatorInterface;
use Pulsar\I18n\Translator;
use Pulsar\I18n\TranslatorInterface;
use Pulsar\Routing\Router;

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

        self::assertTrue($container->has(\Pulsar\I18n\Format\NumberFormatterInterface::class));
        self::assertTrue($container->has(\Pulsar\I18n\Format\DateFormatterInterface::class));
        self::assertTrue($container->has(\Pulsar\I18n\Format\CurrencyFormatterInterface::class));
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

        self::assertTrue($container->has(\Pulsar\I18n\Locale\UrlPrefixExtractor::class));
        self::assertTrue($container->has(\Pulsar\I18n\Locale\LocaleUrlGenerator::class));
        self::assertTrue($container->has(\Pulsar\View\Engine\TemplateLocaleHelper::class));
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
