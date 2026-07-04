<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core\Wiring;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\DatabaseConfig;
use Pulsar\Container\Container;
use Pulsar\Core\Wiring\DatabaseWiring;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\ConnectionManager;
use Pulsar\Database\ConnectionManagerInterface;
use Pulsar\Database\Monitor\MonitoredConnection;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\Router;

#[CoversClass(DatabaseWiring::class)]
final class DatabaseWiringTest extends TestCase
{
    #[Test]
    public function wireRegistersConnectionManagerWhenConfigPresent(): void
    {
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManager(withDatabase: true);
        $configManager->load();

        $wiring = new DatabaseWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        self::assertTrue($container->has(DatabaseConfig::class));
        self::assertTrue($container->has(ConnectionManager::class));
        self::assertTrue($container->has(ConnectionManagerInterface::class));
    }

    #[Test]
    public function wireSkipsWhenNoDatabaseConfig(): void
    {
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManager(withDatabase: false);
        $configManager->load();

        $wiring = new DatabaseWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        self::assertFalse($container->has(DatabaseConfig::class));
        self::assertFalse($container->has(ConnectionManager::class));
    }

    #[Test]
    public function wireDecoratesConnectionWithMonitoringWhenEnabled(): void
    {
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManager(withDatabase: true, monitorEnabled: true);
        $configManager->load();

        new DatabaseWiring()->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        self::assertInstanceOf(MonitoredConnection::class, $container->get(ConnectionInterface::class));
    }

    #[Test]
    public function wireUsesRawConnectionWhenMonitoringDisabled(): void
    {
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        // Monitoring is off by default.
        $configManager = $this->createConfigManager(withDatabase: true);
        $configManager->load();

        new DatabaseWiring()->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        self::assertNotInstanceOf(MonitoredConnection::class, $container->get(ConnectionInterface::class));
    }

    private function createConfigManager(bool $withDatabase, bool $monitorEnabled = false): ConfigManager
    {
        $configPath = sys_get_temp_dir() . '/pulsar_db_wiring_' . bin2hex(random_bytes(4));
        @mkdir($configPath, 0o755, true);

        file_put_contents($configPath . '/app.php', '<?php return ["name" => "Test", "env" => "testing", "debug" => false, "timezone" => "UTC", "locale" => "en"];');
        file_put_contents($configPath . '/observability.php', '<?php return ["logging" => ["default_channel" => "file", "level" => "debug", "channels" => []]];');
        file_put_contents($configPath . '/security.php', '<?php return ["session" => [], "csrf" => [], "headers" => [], "rate_limit" => []];');

        if ($withDatabase) {
            $monitor = $monitorEnabled ? ', "monitor" => ["enabled" => true]' : '';
            file_put_contents($configPath . '/database.php', '<?php return ["default" => "sqlite", "connections" => ["sqlite" => ["driver" => "sqlite", "database" => ":memory:"]]' . $monitor . '];');
        }

        return new ConfigManager($configPath);
    }
}
