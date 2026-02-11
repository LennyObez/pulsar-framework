<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core\Wiring;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\ConfigManager;
use Pulsar\Container\Container;
use Pulsar\Core\Wiring\DiagnosticsWiring;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Observability\Metrics\MetricRegistry;
use Pulsar\Routing\Router;

#[CoversClass(DiagnosticsWiring::class)]
final class DiagnosticsWiringTest extends TestCase
{
    #[Test]
    public function wireRegistersRouteInDebugMode(): void
    {
        $container = new Container();
        $registry = new MetricRegistry();
        $container->instance(MetricRegistry::class, $registry);
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManager(debug: true);
        $configManager->load();

        $wiring = new DiagnosticsWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        $routes = $router->routes();
        $hasDiagRoute = false;
        foreach ($routes as $route) {
            if ($route->path === '/_pulsar/diagnostics') {
                $hasDiagRoute = true;
                break;
            }
        }
        self::assertTrue($hasDiagRoute);
    }

    #[Test]
    public function wireSkipsInProductionMode(): void
    {
        $container = new Container();
        $registry = new MetricRegistry();
        $container->instance(MetricRegistry::class, $registry);
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManager(debug: false);
        $configManager->load();

        $wiring = new DiagnosticsWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        self::assertSame([], $router->routes());
    }

    #[Test]
    public function wireSkipsWithoutMetricRegistry(): void
    {
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManager(debug: true);
        $configManager->load();

        $wiring = new DiagnosticsWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        self::assertSame([], $router->routes());
    }

    private function createConfigManager(bool $debug): ConfigManager
    {
        $configPath = sys_get_temp_dir() . '/pulsar_diag_wiring_' . bin2hex(random_bytes(4));
        @mkdir($configPath, 0o755, true);

        $debugStr = $debug ? 'true' : 'false';
        file_put_contents($configPath . '/app.php', '<?php return ["name" => "Test", "env" => "testing", "debug" => ' . $debugStr . ', "timezone" => "UTC", "locale" => "en"];');
        file_put_contents($configPath . '/observability.php', '<?php return ["logging" => ["default_channel" => "file", "level" => "debug", "channels" => []]];');
        file_put_contents($configPath . '/security.php', '<?php return ["session" => [], "csrf" => [], "headers" => [], "rate_limit" => []];');

        return new ConfigManager($configPath);
    }
}
