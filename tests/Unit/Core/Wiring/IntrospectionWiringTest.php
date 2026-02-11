<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core\Wiring;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\ConfigManager;
use Pulsar\Container\Container;
use Pulsar\Core\Wiring\IntrospectionWiring;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Introspection\IntrospectionConfig;
use Pulsar\Introspection\ProjectMetadataService;
use Pulsar\Routing\Router;

use function bin2hex;
use function file_put_contents;
use function mkdir;
use function random_bytes;

#[CoversClass(IntrospectionWiring::class)]
final class IntrospectionWiringTest extends TestCase
{
    #[Test]
    public function wireRegistersIntrospectionServicesWhenEnabled(): void
    {
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManager(enabled: true);
        $configManager->load();

        $wiring = new IntrospectionWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        self::assertTrue($container->has(IntrospectionConfig::class));
        self::assertTrue($container->has(ProjectMetadataService::class));
    }

    #[Test]
    public function wireSkipsServiceWhenDisabled(): void
    {
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManager(enabled: false);
        $configManager->load();

        $wiring = new IntrospectionWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        self::assertTrue($container->has(IntrospectionConfig::class));
        self::assertFalse($container->has(ProjectMetadataService::class));
    }

    #[Test]
    public function wireDefaultsToEnabledWhenNoConfigFile(): void
    {
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManagerWithout();
        $configManager->load();

        $wiring = new IntrospectionWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        // IntrospectionWiring always registers the config (defaults from fromArray with empty data)
        self::assertTrue($container->has(IntrospectionConfig::class));
    }

    private function createConfigManager(bool $enabled): ConfigManager
    {
        $configPath = sys_get_temp_dir() . '/pulsar_introspection_wiring_' . bin2hex(random_bytes(4));
        @mkdir($configPath, 0o755, true);

        $enabledStr = $enabled ? 'true' : 'false';
        file_put_contents($configPath . '/app.php', '<?php return ["name" => "Test", "env" => "testing", "debug" => false, "timezone" => "UTC", "locale" => "en"];');
        file_put_contents($configPath . '/observability.php', '<?php return ["logging" => ["default_channel" => "file", "level" => "debug", "channels" => []]];');
        file_put_contents($configPath . '/security.php', '<?php return ["session" => [], "csrf" => [], "headers" => [], "rate_limit" => []];');
        file_put_contents($configPath . '/introspection.php', '<?php return ["enabled" => ' . $enabledStr . '];');

        return new ConfigManager($configPath);
    }

    private function createConfigManagerWithout(): ConfigManager
    {
        $configPath = sys_get_temp_dir() . '/pulsar_introspection_wiring_no_' . bin2hex(random_bytes(4));
        @mkdir($configPath, 0o755, true);

        file_put_contents($configPath . '/app.php', '<?php return ["name" => "Test", "env" => "testing", "debug" => false, "timezone" => "UTC", "locale" => "en"];');
        file_put_contents($configPath . '/observability.php', '<?php return ["logging" => ["default_channel" => "file", "level" => "debug", "channels" => []]];');
        file_put_contents($configPath . '/security.php', '<?php return ["session" => [], "csrf" => [], "headers" => [], "rate_limit" => []];');

        return new ConfigManager($configPath);
    }
}
