<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core\Wiring;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface as PsrEventDispatcherInterface;
use Psr\EventDispatcher\ListenerProviderInterface as PsrListenerProviderInterface;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\EventConfig;
use Pulsar\Container\Container;
use Pulsar\Core\Wiring\EventWiring;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Event\Internal\EventDispatcher;
use Pulsar\Event\Internal\ListenerProvider;
use Pulsar\Event\Internal\StormGuard;
use Pulsar\Event\ListenerProviderInterface;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\Router;

#[CoversClass(EventWiring::class)]
final class EventWiringTest extends TestCase
{
    #[Test]
    public function wireRegistersEventServicesWhenEnabled(): void
    {
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManager(enabled: true);
        $configManager->load();

        $wiring = new EventWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        self::assertTrue($container->has(EventConfig::class));
        self::assertTrue($container->has(StormGuard::class));
        self::assertTrue($container->has(ListenerProvider::class));
        self::assertTrue($container->has(ListenerProviderInterface::class));
        self::assertTrue($container->has(PsrListenerProviderInterface::class));
        self::assertTrue($container->has(EventDispatcher::class));
        self::assertTrue($container->has(EventDispatcherInterface::class));
        self::assertTrue($container->has(PsrEventDispatcherInterface::class));
    }

    #[Test]
    public function wireSkipsWhenDisabled(): void
    {
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManager(enabled: false);
        $configManager->load();

        $wiring = new EventWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        self::assertTrue($container->has(EventConfig::class));
        self::assertFalse($container->has(EventDispatcher::class));
    }

    #[Test]
    public function wireSkipsWhenNoEventConfig(): void
    {
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManagerWithoutEvent();
        $configManager->load();

        $wiring = new EventWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        self::assertFalse($container->has(EventConfig::class));
        self::assertFalse($container->has(EventDispatcher::class));
    }

    private function createConfigManager(bool $enabled): ConfigManager
    {
        $configPath = sys_get_temp_dir() . '/pulsar_event_wiring_' . bin2hex(random_bytes(4));
        @mkdir($configPath, 0o755, true);

        file_put_contents($configPath . '/app.php', '<?php return ["name" => "Test", "env" => "testing", "debug" => false, "timezone" => "UTC", "locale" => "en"];');
        file_put_contents($configPath . '/observability.php', '<?php return ["logging" => ["default_channel" => "file", "level" => "debug", "channels" => []]];');
        file_put_contents($configPath . '/security.php', '<?php return ["session" => [], "csrf" => [], "headers" => [], "rate_limit" => []];');
        $enabledStr = $enabled ? 'true' : 'false';
        file_put_contents($configPath . '/event.php', '<?php return ["enabled" => ' . $enabledStr . '];');

        return new ConfigManager($configPath);
    }

    private function createConfigManagerWithoutEvent(): ConfigManager
    {
        $configPath = sys_get_temp_dir() . '/pulsar_event_wiring_no_' . bin2hex(random_bytes(4));
        @mkdir($configPath, 0o755, true);

        file_put_contents($configPath . '/app.php', '<?php return ["name" => "Test", "env" => "testing", "debug" => false, "timezone" => "UTC", "locale" => "en"];');
        file_put_contents($configPath . '/observability.php', '<?php return ["logging" => ["default_channel" => "file", "level" => "debug", "channels" => []]];');
        file_put_contents($configPath . '/security.php', '<?php return ["session" => [], "csrf" => [], "headers" => [], "rate_limit" => []];');

        return new ConfigManager($configPath);
    }
}
