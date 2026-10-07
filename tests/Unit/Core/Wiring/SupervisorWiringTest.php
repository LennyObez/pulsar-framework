<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core\Wiring;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\SupervisorConfig;
use Pulsar\Container\Container;
use Pulsar\Core\Wiring\SupervisorWiring;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\Router;
use Pulsar\Supervisor\PreflightCheck\PreflightRunner;
use Pulsar\Supervisor\PreflightCheck\PreflightRunnerInterface;
use Pulsar\Supervisor\Supervisor;
use Pulsar\Supervisor\SupervisorInterface;

use function bin2hex;
use function file_put_contents;
use function mkdir;
use function random_bytes;

#[CoversClass(SupervisorWiring::class)]
final class SupervisorWiringTest extends TestCase
{
    #[Test]
    public function wireRegistersSupervisorWhenEnabled(): void
    {
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManager(enabled: true);
        $configManager->load();

        $wiring = new SupervisorWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        self::assertTrue($container->has(SupervisorConfig::class));
        self::assertTrue($container->has(Supervisor::class));
        self::assertTrue($container->has(SupervisorInterface::class));
        self::assertTrue($container->has(PreflightRunner::class));
        self::assertTrue($container->has(PreflightRunnerInterface::class));
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

        $wiring = new SupervisorWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        self::assertTrue($container->has(SupervisorConfig::class));
        self::assertFalse($container->has(Supervisor::class));
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

        $wiring = new SupervisorWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        self::assertFalse($container->has(SupervisorConfig::class));
    }

    private function createConfigManager(bool $enabled): ConfigManager
    {
        $configPath = sys_get_temp_dir() . '/pulsar_supervisor_wiring_' . bin2hex(random_bytes(4));
        @mkdir($configPath, 0o755, true);

        $enabledStr = $enabled ? 'true' : 'false';
        file_put_contents($configPath . '/app.php', '<?php return ["name" => "Test", "env" => "testing", "debug" => false, "timezone" => "UTC", "locale" => "en"];');
        file_put_contents($configPath . '/observability.php', '<?php return ["logging" => ["default_channel" => "file", "level" => "debug", "channels" => []]];');
        file_put_contents($configPath . '/security.php', '<?php return ["session" => [], "csrf" => [], "headers" => [], "rate_limit" => []];');
        file_put_contents($configPath . '/supervisor.php', '<?php return ["enabled" => ' . $enabledStr . '];');

        return new ConfigManager($configPath);
    }

    private function createConfigManagerWithout(): ConfigManager
    {
        $configPath = sys_get_temp_dir() . '/pulsar_supervisor_wiring_no_' . bin2hex(random_bytes(4));
        @mkdir($configPath, 0o755, true);

        file_put_contents($configPath . '/app.php', '<?php return ["name" => "Test", "env" => "testing", "debug" => false, "timezone" => "UTC", "locale" => "en"];');
        file_put_contents($configPath . '/observability.php', '<?php return ["logging" => ["default_channel" => "file", "level" => "debug", "channels" => []]];');
        file_put_contents($configPath . '/security.php', '<?php return ["session" => [], "csrf" => [], "headers" => [], "rate_limit" => []];');

        return new ConfigManager($configPath);
    }
}
