<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core\Wiring;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\FeatureFlagConfig;
use Pulsar\Container\Container;
use Pulsar\Core\Wiring\FeatureFlagWiring;
use Pulsar\FeatureFlag\FeatureFlagManager;
use Pulsar\FeatureFlag\FeatureFlagManagerInterface;
use Pulsar\FeatureFlag\FlagEvaluationLog;
use Pulsar\FeatureFlag\FlagEvaluationLogInterface;
use Pulsar\FeatureFlag\FlagStorageInterface;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\Router;

use function bin2hex;
use function file_put_contents;
use function mkdir;
use function random_bytes;

#[CoversClass(FeatureFlagWiring::class)]
final class FeatureFlagWiringTest extends TestCase
{
    #[Test]
    public function wireRegistersFeatureFlagServicesWhenEnabled(): void
    {
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManager(enabled: true, storage: 'memory');
        $configManager->load();

        $wiring = new FeatureFlagWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        self::assertTrue($container->has(FeatureFlagConfig::class));
        self::assertTrue($container->has(FlagStorageInterface::class));
        self::assertTrue($container->has(FlagEvaluationLog::class));
        self::assertTrue($container->has(FlagEvaluationLogInterface::class));
        self::assertTrue($container->has(FeatureFlagManager::class));
        self::assertTrue($container->has(FeatureFlagManagerInterface::class));
    }

    #[Test]
    public function wireSkipsWhenDisabled(): void
    {
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManager(enabled: false, storage: 'memory');
        $configManager->load();

        $wiring = new FeatureFlagWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        self::assertTrue($container->has(FeatureFlagConfig::class));
        self::assertFalse($container->has(FeatureFlagManager::class));
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

        $wiring = new FeatureFlagWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        self::assertFalse($container->has(FeatureFlagConfig::class));
    }

    private function createConfigManager(bool $enabled, string $storage): ConfigManager
    {
        $configPath = sys_get_temp_dir() . '/pulsar_ff_wiring_' . bin2hex(random_bytes(4));
        @mkdir($configPath, 0o755, true);

        $enabledStr = $enabled ? 'true' : 'false';
        file_put_contents($configPath . '/app.php', '<?php return ["name" => "Test", "env" => "testing", "debug" => false, "timezone" => "UTC", "locale" => "en"];');
        file_put_contents($configPath . '/observability.php', '<?php return ["logging" => ["default_channel" => "file", "level" => "debug", "channels" => []]];');
        file_put_contents($configPath . '/security.php', '<?php return ["session" => [], "csrf" => [], "headers" => [], "rate_limit" => []];');
        file_put_contents($configPath . '/features.php', '<?php return ["enabled" => ' . $enabledStr . ', "storage" => "' . $storage . '", "flags" => []];');

        return new ConfigManager($configPath);
    }

    private function createConfigManagerWithout(): ConfigManager
    {
        $configPath = sys_get_temp_dir() . '/pulsar_ff_wiring_no_' . bin2hex(random_bytes(4));
        @mkdir($configPath, 0o755, true);

        file_put_contents($configPath . '/app.php', '<?php return ["name" => "Test", "env" => "testing", "debug" => false, "timezone" => "UTC", "locale" => "en"];');
        file_put_contents($configPath . '/observability.php', '<?php return ["logging" => ["default_channel" => "file", "level" => "debug", "channels" => []]];');
        file_put_contents($configPath . '/security.php', '<?php return ["session" => [], "csrf" => [], "headers" => [], "rate_limit" => []];');

        return new ConfigManager($configPath);
    }
}
