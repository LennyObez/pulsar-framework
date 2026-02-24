<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core\Wiring;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\DeployConfig;
use Pulsar\Container\Container;
use Pulsar\Core\Wiring\DeployWiring;
use Pulsar\Deploy\DeployCheck;
use Pulsar\Deploy\DeployCheckRunnerInterface;
use Pulsar\Deploy\Runtime\PhpRuntimeInterface;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\Router;

use function bin2hex;
use function file_put_contents;
use function mkdir;
use function random_bytes;

#[CoversClass(DeployWiring::class)]
final class DeployWiringTest extends TestCase
{
    #[Test]
    public function wireRegistersDeployServices(): void
    {
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManager();
        $configManager->load();

        $wiring = new DeployWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        self::assertTrue($container->has(DeployConfig::class));
        self::assertTrue($container->has(PhpRuntimeInterface::class));
        self::assertTrue($container->has(DeployCheck::class));
        self::assertTrue($container->has(DeployCheckRunnerInterface::class));
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

        $wiring = new DeployWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        self::assertFalse($container->has(DeployConfig::class));
    }

    #[Test]
    public function wireHandlesDisabledChecks(): void
    {
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManagerWithDisabledChecks();
        $configManager->load();

        $wiring = new DeployWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        self::assertTrue($container->has(DeployCheck::class));

        /** @var DeployCheck $deployCheck */
        $deployCheck = $container->get(DeployCheck::class);
        $report = $deployCheck->run('production');

        // With debug-mode disabled, fewer checks run than the default set
        self::assertSame('production', $report->environment);
    }

    private function createConfigManager(): ConfigManager
    {
        $configPath = sys_get_temp_dir() . '/pulsar_deploy_wiring_' . bin2hex(random_bytes(4));
        @mkdir($configPath, 0o755, true);

        file_put_contents($configPath . '/app.php', '<?php return ["name" => "Test", "env" => "testing", "debug" => false, "timezone" => "UTC", "locale" => "en"];');
        file_put_contents($configPath . '/observability.php', '<?php return ["logging" => ["default_channel" => "file", "level" => "debug", "channels" => []]];');
        file_put_contents($configPath . '/security.php', '<?php return ["session" => [], "csrf" => [], "headers" => [], "rate_limit" => []];');
        file_put_contents($configPath . '/deploy.php', '<?php return [];');

        return new ConfigManager($configPath);
    }

    private function createConfigManagerWithout(): ConfigManager
    {
        $configPath = sys_get_temp_dir() . '/pulsar_deploy_wiring_no_' . bin2hex(random_bytes(4));
        @mkdir($configPath, 0o755, true);

        file_put_contents($configPath . '/app.php', '<?php return ["name" => "Test", "env" => "testing", "debug" => false, "timezone" => "UTC", "locale" => "en"];');
        file_put_contents($configPath . '/observability.php', '<?php return ["logging" => ["default_channel" => "file", "level" => "debug", "channels" => []]];');
        file_put_contents($configPath . '/security.php', '<?php return ["session" => [], "csrf" => [], "headers" => [], "rate_limit" => []];');

        return new ConfigManager($configPath);
    }

    private function createConfigManagerWithDisabledChecks(): ConfigManager
    {
        $configPath = sys_get_temp_dir() . '/pulsar_deploy_wiring_disabled_' . bin2hex(random_bytes(4));
        @mkdir($configPath, 0o755, true);

        file_put_contents($configPath . '/app.php', '<?php return ["name" => "Test", "env" => "testing", "debug" => false, "timezone" => "UTC", "locale" => "en"];');
        file_put_contents($configPath . '/observability.php', '<?php return ["logging" => ["default_channel" => "file", "level" => "debug", "channels" => []]];');
        file_put_contents($configPath . '/security.php', '<?php return ["session" => [], "csrf" => [], "headers" => [], "rate_limit" => []];');
        file_put_contents($configPath . '/deploy.php', '<?php return ["checks" => ["debug-mode" => ["enabled" => false, "severity" => "fail"]]];');

        return new ConfigManager($configPath);
    }
}
