<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core\Wiring;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\StorageConfig;
use Pulsar\Container\Container;
use Pulsar\Core\Wiring\StorageWiring;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\Router;
use Pulsar\Storage\StorageManager;

#[CoversClass(StorageWiring::class)]
final class StorageWiringTest extends TestCase
{
    #[Test]
    public function wireRegistersStorageManagerWhenConfigPresent(): void
    {
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManager(withStorage: true);
        $configManager->load();

        $wiring = new StorageWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        self::assertTrue($container->has(StorageConfig::class));
        self::assertTrue($container->has(StorageManager::class));
    }

    #[Test]
    public function wireSkipsWhenNoStorageConfig(): void
    {
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManager(withStorage: false);
        $configManager->load();

        $wiring = new StorageWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        self::assertFalse($container->has(StorageConfig::class));
        self::assertFalse($container->has(StorageManager::class));
    }

    private function createConfigManager(bool $withStorage): ConfigManager
    {
        $configPath = sys_get_temp_dir() . '/pulsar_storage_wiring_' . bin2hex(random_bytes(4));
        @mkdir($configPath, 0o755, true);

        file_put_contents($configPath . '/app.php', '<?php return ["name" => "Test", "env" => "testing", "debug" => false, "timezone" => "UTC", "locale" => "en"];');
        file_put_contents($configPath . '/observability.php', '<?php return ["logging" => ["default_channel" => "file", "level" => "debug", "channels" => []]];');
        file_put_contents($configPath . '/security.php', '<?php return ["session" => [], "csrf" => [], "headers" => [], "rate_limit" => []];');

        if ($withStorage) {
            $storagePath = sys_get_temp_dir() . '/pulsar_storage_' . bin2hex(random_bytes(4));
            @mkdir($storagePath, 0o755, true);
            file_put_contents($configPath . '/storage.php', '<?php return ["default" => "local", "disks" => ["local" => ["driver" => "local", "root" => "' . str_replace('\\', '\\\\', $storagePath) . '"]]];');
        }

        return new ConfigManager($configPath);
    }
}
