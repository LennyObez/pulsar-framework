<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core\Wiring;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\ConfigManager;
use Pulsar\Container\Container;
use Pulsar\Core\Wiring\SagaWiring;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\ConnectionManagerInterface;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\Router;
use Pulsar\Saga\SagaStateStorageInterface;
use Pulsar\Saga\Storage\InMemorySagaStateStorage;
use Pulsar\Workflow\Internal\Storage\DatabaseSagaStateStorage;

use function bin2hex;
use function file_put_contents;
use function random_bytes;
use function sys_get_temp_dir;

#[CoversClass(SagaWiring::class)]
final class SagaWiringTest extends TestCase
{
    #[Test]
    public function registersInMemoryStorageWhenNoConnectionManagerBound(): void
    {
        $container = new Container();

        $this->wire($container);

        self::assertTrue($container->has(SagaStateStorageInterface::class));
        self::assertInstanceOf(
            InMemorySagaStateStorage::class,
            $container->get(SagaStateStorageInterface::class),
        );
    }

    #[Test]
    public function registersDatabaseStorageWhenConnectionManagerAvailable(): void
    {
        $container = new Container();
        $connection = $this->createStub(ConnectionInterface::class);
        $manager = $this->createStub(ConnectionManagerInterface::class);
        $manager->method('connection')->willReturn($connection);
        $container->instance(ConnectionManagerInterface::class, $manager);

        $this->wire($container);

        self::assertInstanceOf(
            DatabaseSagaStateStorage::class,
            $container->get(SagaStateStorageInterface::class),
        );
    }

    #[Test]
    public function doesNotOverrideAnExistingStorageBinding(): void
    {
        $container = new Container();
        $existing = $this->createStub(SagaStateStorageInterface::class);
        $container->instance(SagaStateStorageInterface::class, $existing);

        $this->wire($container);

        self::assertSame($existing, $container->get(SagaStateStorageInterface::class));
    }

    private function wire(Container $container): void
    {
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $wiring = new SagaWiring();
        $wiring->wire($container, $this->configManager(), $middleware, $middlewareRegistry, $router);
    }

    private function configManager(): ConfigManager
    {
        $configPath = sys_get_temp_dir() . '/pulsar_saga_wiring_' . bin2hex(random_bytes(4));
        @mkdir($configPath, 0o755, true);

        file_put_contents($configPath . '/app.php', '<?php return ["name" => "Test", "env" => "testing", "debug" => false, "timezone" => "UTC", "locale" => "en"];');
        file_put_contents($configPath . '/observability.php', '<?php return ["logging" => ["default_channel" => "file", "level" => "debug", "channels" => []]];');
        file_put_contents($configPath . '/security.php', '<?php return ["session" => [], "csrf" => [], "headers" => [], "rate_limit" => []];');

        return new ConfigManager($configPath);
    }
}
