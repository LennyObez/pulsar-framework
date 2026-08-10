<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core\Wiring;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\RuntimeConfig;
use Pulsar\Config\SessionConfig;
use Pulsar\Container\Container;
use Pulsar\Core\Wiring\RuntimeWiring;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\Router;
use Pulsar\Runtime\LeakDetector;
use Pulsar\Runtime\RequestResetRegistry;
use Pulsar\Runtime\RequestSandbox;
use Pulsar\Runtime\RuntimeFactory;
use Pulsar\Runtime\RuntimeResolver;
use Pulsar\Security\Session\Handler\ArrayHandler;
use Pulsar\Security\Session\SessionManager;

use function bin2hex;
use function file_put_contents;
use function mkdir;
use function random_bytes;

#[CoversClass(RuntimeWiring::class)]
final class RuntimeWiringTest extends TestCase
{
    #[Test]
    public function wireRegistersRuntimeServices(): void
    {
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManager();
        $configManager->load();

        $wiring = new RuntimeWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        self::assertTrue($container->has(RuntimeConfig::class));
        self::assertTrue($container->has(RequestResetRegistry::class));
        self::assertTrue($container->has(LeakDetector::class));
        self::assertTrue($container->has(RequestSandbox::class));
        self::assertTrue($container->has(RuntimeResolver::class));
        self::assertTrue($container->has(RuntimeFactory::class));
    }

    #[Test]
    public function wireRegistersTheSessionManagerAsResettable(): void
    {
        // A SessionManager bound by SecurityWiring (which runs
        // earlier) must be registered for per-request reset, or its singleton
        // bleeds sessions across users on persistent workers.
        $container = new Container();
        $container->instance(SessionManager::class, new SessionManager(
            new ArrayHandler(),
            new SessionConfig(
                cookieName: 'TEST',
                lifetime: 3600,
                cookieHttpOnly: true,
                cookieSecure: true,
                cookieSameSite: 'Strict',
                regenerateOnPrivilegeChange: true,
            ),
        ));

        $configManager = $this->createConfigManager();
        $configManager->load();

        new RuntimeWiring()->wire(
            $container,
            $configManager,
            new MiddlewarePipeline($container),
            new MiddlewareRegistry(),
            new Router(),
        );

        /** @var RequestResetRegistry $registry */
        $registry = $container->get(RequestResetRegistry::class);
        self::assertContains(SessionManager::class, $registry->resettableIds);
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

        $wiring = new RuntimeWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        self::assertFalse($container->has(RuntimeConfig::class));
    }

    private function createConfigManager(): ConfigManager
    {
        $configPath = sys_get_temp_dir() . '/pulsar_runtime_wiring_' . bin2hex(random_bytes(4));
        @mkdir($configPath, 0o755, true);

        file_put_contents($configPath . '/app.php', '<?php return ["name" => "Test", "env" => "testing", "debug" => false, "timezone" => "UTC", "locale" => "en"];');
        file_put_contents($configPath . '/observability.php', '<?php return ["logging" => ["default_channel" => "file", "level" => "debug", "channels" => []]];');
        file_put_contents($configPath . '/security.php', '<?php return ["session" => [], "csrf" => [], "headers" => [], "rate_limit" => []];');
        file_put_contents($configPath . '/runtime.php', '<?php return [];');

        return new ConfigManager($configPath);
    }

    private function createConfigManagerWithout(): ConfigManager
    {
        $configPath = sys_get_temp_dir() . '/pulsar_runtime_wiring_no_' . bin2hex(random_bytes(4));
        @mkdir($configPath, 0o755, true);

        file_put_contents($configPath . '/app.php', '<?php return ["name" => "Test", "env" => "testing", "debug" => false, "timezone" => "UTC", "locale" => "en"];');
        file_put_contents($configPath . '/observability.php', '<?php return ["logging" => ["default_channel" => "file", "level" => "debug", "channels" => []]];');
        file_put_contents($configPath . '/security.php', '<?php return ["session" => [], "csrf" => [], "headers" => [], "rate_limit" => []];');

        return new ConfigManager($configPath);
    }
}
