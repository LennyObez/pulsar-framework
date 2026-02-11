<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core\Wiring;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Pulsar\Config\ConfigManager;
use Pulsar\Container\Container;
use Pulsar\Core\Wiring\LoggingWiring;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Observability\Log\Logger;
use Pulsar\Observability\Log\Sink\DeferredSink;
use Pulsar\Observability\Log\Sink\DeferredSinkInterface;
use Pulsar\Routing\Router;

#[CoversClass(LoggingWiring::class)]
final class LoggingWiringTest extends TestCase
{
    #[Test]
    public function wireRegistersLoggerAndDeferredSink(): void
    {
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManager();
        $configManager->load();

        $wiring = new LoggingWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        self::assertTrue($container->has(LoggerInterface::class));
        self::assertTrue($container->has(Logger::class));
        self::assertTrue($container->has(DeferredSink::class));
        self::assertTrue($container->has(DeferredSinkInterface::class));
    }

    #[Test]
    public function wireLoggerIsInstanceOfPsrLogger(): void
    {
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManager();
        $configManager->load();

        $wiring = new LoggingWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        $logger = $container->get(LoggerInterface::class);
        self::assertInstanceOf(LoggerInterface::class, $logger);
    }

    private function createConfigManager(): ConfigManager
    {
        $configPath = sys_get_temp_dir() . '/pulsar_logging_wiring_' . bin2hex(random_bytes(4));
        @mkdir($configPath, 0o755, true);

        file_put_contents($configPath . '/app.php', '<?php return ["name" => "Test", "env" => "testing", "debug" => false, "timezone" => "UTC", "locale" => "en"];');
        file_put_contents($configPath . '/observability.php', '<?php return ["logging" => ["default_channel" => "file", "level" => "debug", "channels" => []]];');
        file_put_contents($configPath . '/security.php', '<?php return ["session" => [], "csrf" => [], "headers" => [], "rate_limit" => []];');

        return new ConfigManager($configPath);
    }
}
