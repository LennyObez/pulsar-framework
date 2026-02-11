<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core\Wiring;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\ConfigManager;
use Pulsar\Container\Container;
use Pulsar\Core\Wiring\MetricsWiring;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Http\RouteContext;
use Pulsar\Observability\Metrics\MetricRegistry;
use Pulsar\Routing\Router;

#[CoversClass(MetricsWiring::class)]
final class MetricsWiringTest extends TestCase
{
    #[Test]
    public function wireRegistersMetricsWhenEnabled(): void
    {
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManager(enabled: true, exporterEnabled: false);
        $configManager->load();

        $wiring = new MetricsWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        self::assertTrue($container->has(MetricRegistry::class));
        self::assertTrue($container->has(RouteContext::class));
    }

    #[Test]
    public function wireSkipsWhenDisabled(): void
    {
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManager(enabled: false, exporterEnabled: false);
        $configManager->load();

        $wiring = new MetricsWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        self::assertFalse($container->has(MetricRegistry::class));
    }

    #[Test]
    public function wireRegistersExporterEndpointWhenEnabled(): void
    {
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManager(enabled: true, exporterEnabled: true);
        $configManager->load();

        $wiring = new MetricsWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        self::assertTrue($container->has(MetricRegistry::class));
        // Verify route was registered by checking the router has the metrics endpoint
        $routes = $router->routes();
        $hasMetricsRoute = false;
        foreach ($routes as $route) {
            if ($route->path === '/metrics') {
                $hasMetricsRoute = true;
                break;
            }
        }
        self::assertTrue($hasMetricsRoute);
    }

    private function createConfigManager(bool $enabled, bool $exporterEnabled): ConfigManager
    {
        $configPath = sys_get_temp_dir() . '/pulsar_metrics_wiring_' . bin2hex(random_bytes(4));
        @mkdir($configPath, 0o755, true);

        $enabledStr = $enabled ? 'true' : 'false';
        $exporterStr = $exporterEnabled ? 'true' : 'false';
        file_put_contents($configPath . '/app.php', '<?php return ["name" => "Test", "env" => "testing", "debug" => false, "timezone" => "UTC", "locale" => "en"];');
        file_put_contents($configPath . '/observability.php', '<?php return ["logging" => ["default_channel" => "file", "level" => "debug", "channels" => []], "metrics" => ["enabled" => ' . $enabledStr . ', "exporters" => ["openmetrics" => ["enabled" => ' . $exporterStr . ', "endpoint" => "/metrics"]]]];');
        file_put_contents($configPath . '/security.php', '<?php return ["session" => [], "csrf" => [], "headers" => [], "rate_limit" => []];');

        return new ConfigManager($configPath);
    }
}
