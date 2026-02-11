<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core\Wiring;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\QueueConfig;
use Pulsar\Container\Container;
use Pulsar\Core\Wiring\QueueWiring;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Queue\DeadLetterQueue;
use Pulsar\Queue\Monitor\MetricsCollector;
use Pulsar\Queue\QueueDriverInterface;
use Pulsar\Queue\QueueManager;
use Pulsar\Queue\Retry\QueueRetryPolicy;
use Pulsar\Queue\Worker;
use Pulsar\Queue\WorkerOptions;
use Pulsar\Routing\Router;
use Random\Randomizer;

use function bin2hex;
use function file_put_contents;
use function mkdir;
use function random_bytes;

#[CoversClass(QueueWiring::class)]
final class QueueWiringTest extends TestCase
{
    #[Test]
    public function wireRegistersQueueServicesWhenEnabled(): void
    {
        $container = new Container();
        $container->instance(Randomizer::class, new Randomizer());
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManager(enabled: true, driver: 'sync');
        $configManager->load();

        $wiring = new QueueWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        self::assertTrue($container->has(QueueConfig::class));
        self::assertTrue($container->has(QueueDriverInterface::class));
        self::assertTrue($container->has(MetricsCollector::class));
        self::assertTrue($container->has(QueueManager::class));
        self::assertTrue($container->has(WorkerOptions::class));
        self::assertTrue($container->has(QueueRetryPolicy::class));
        self::assertTrue($container->has(DeadLetterQueue::class));
        self::assertTrue($container->has(Worker::class));
    }

    #[Test]
    public function wireUsesMemoryDriverWhenConfigured(): void
    {
        $container = new Container();
        $container->instance(Randomizer::class, new Randomizer());
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManager(enabled: true, driver: 'memory');
        $configManager->load();

        $wiring = new QueueWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        self::assertTrue($container->has(QueueDriverInterface::class));
    }

    #[Test]
    public function wireSkipsWhenDisabled(): void
    {
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManager(enabled: false, driver: 'sync');
        $configManager->load();

        $wiring = new QueueWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        self::assertTrue($container->has(QueueConfig::class));
        self::assertFalse($container->has(QueueManager::class));
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

        $wiring = new QueueWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        self::assertFalse($container->has(QueueConfig::class));
    }

    private function createConfigManager(bool $enabled, string $driver): ConfigManager
    {
        $configPath = sys_get_temp_dir() . '/pulsar_queue_wiring_' . bin2hex(random_bytes(4));
        @mkdir($configPath, 0o755, true);

        $enabledStr = $enabled ? 'true' : 'false';
        file_put_contents($configPath . '/app.php', '<?php return ["name" => "Test", "env" => "testing", "debug" => false, "timezone" => "UTC", "locale" => "en"];');
        file_put_contents($configPath . '/observability.php', '<?php return ["logging" => ["default_channel" => "file", "level" => "debug", "channels" => []]];');
        file_put_contents($configPath . '/security.php', '<?php return ["session" => [], "csrf" => [], "headers" => [], "rate_limit" => []];');
        file_put_contents($configPath . '/queue.php', '<?php return ["enabled" => ' . $enabledStr . ', "driver" => "' . $driver . '"];');

        return new ConfigManager($configPath);
    }

    private function createConfigManagerWithout(): ConfigManager
    {
        $configPath = sys_get_temp_dir() . '/pulsar_queue_wiring_no_' . bin2hex(random_bytes(4));
        @mkdir($configPath, 0o755, true);

        file_put_contents($configPath . '/app.php', '<?php return ["name" => "Test", "env" => "testing", "debug" => false, "timezone" => "UTC", "locale" => "en"];');
        file_put_contents($configPath . '/observability.php', '<?php return ["logging" => ["default_channel" => "file", "level" => "debug", "channels" => []]];');
        file_put_contents($configPath . '/security.php', '<?php return ["session" => [], "csrf" => [], "headers" => [], "rate_limit" => []];');

        return new ConfigManager($configPath);
    }
}
