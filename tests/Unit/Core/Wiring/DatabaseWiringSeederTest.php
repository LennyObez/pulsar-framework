<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core\Wiring;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\ConfigManager;
use Pulsar\Container\Container;
use Pulsar\Core\Wiring\DatabaseWiring;
use Pulsar\Database\Seeder\SeederRunner;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\Router;

use function bin2hex;
use function file_put_contents;
use function mkdir;
use function random_bytes;
use function sys_get_temp_dir;

#[CoversClass(DatabaseWiring::class)]
final class DatabaseWiringSeederTest extends TestCase
{
    #[Test]
    public function seederRunnerIsResolvableAfterWiring(): void
    {
        // Arrange
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();
        $configManager = $this->createSqliteConfigManager();
        $configManager->load();

        // Act
        $wiring = new DatabaseWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        // Assert: SeederRunner binding is registered
        self::assertTrue(
            $container->has(SeederRunner::class),
            'SeederRunner must be registered in the container after DatabaseWiring',
        );
    }

    #[Test]
    public function seederRunnerIsLazilyBound(): void
    {
        // Arrange
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();
        $configManager = $this->createSqliteConfigManager();
        $configManager->load();

        // Act
        $wiring = new DatabaseWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        // Assert: The binding is registered but not yet instantiated.
        // Container::getInstances() returns only eagerly-created instances.
        // SeederRunner should NOT appear there until explicitly resolved.
        $instanceIds = $container->getInstances();
        self::assertNotContains(
            SeederRunner::class,
            $instanceIds,
            'SeederRunner must be lazy-bound, not eagerly instantiated',
        );
    }

    #[Test]
    public function seederRunnerResolvesToCorrectType(): void
    {
        // Arrange
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();
        $configManager = $this->createSqliteConfigManager();
        $configManager->load();

        $wiring = new DatabaseWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        // Act: resolve the lazy binding
        $seederRunner = $container->get(SeederRunner::class);

        // Assert
        self::assertInstanceOf(SeederRunner::class, $seederRunner);
    }

    private function createSqliteConfigManager(): ConfigManager
    {
        $configPath = sys_get_temp_dir() . '/pulsar_seeder_wiring_' . bin2hex(random_bytes(4));
        @mkdir($configPath, 0o755, true);

        file_put_contents(
            $configPath . '/app.php',
            '<?php return ["name" => "Test", "env" => "testing", "debug" => false, "timezone" => "UTC", "locale" => "en"];',
        );
        file_put_contents(
            $configPath . '/observability.php',
            '<?php return ["logging" => ["default_channel" => "file", "level" => "debug", "channels" => []]];',
        );
        file_put_contents(
            $configPath . '/security.php',
            '<?php return ["session" => [], "csrf" => [], "headers" => [], "rate_limit" => []];',
        );
        file_put_contents(
            $configPath . '/database.php',
            '<?php return ["default" => "sqlite", "connections" => ["sqlite" => ["driver" => "sqlite", "database" => ":memory:"]]];',
        );

        return new ConfigManager($configPath);
    }
}
