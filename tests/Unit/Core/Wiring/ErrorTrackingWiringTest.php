<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core\Wiring;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\ConfigManager;
use Pulsar\Container\Container;
use Pulsar\Core\Wiring\ErrorTrackingWiring;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Observability\ErrorTracking\ErrorAggregator;
use Pulsar\Observability\ErrorTracking\ErrorAggregatorInterface;
use Pulsar\Observability\ErrorTracking\SensitiveDataScrubber;
use Pulsar\Routing\Router;

#[CoversClass(ErrorTrackingWiring::class)]
final class ErrorTrackingWiringTest extends TestCase
{
    #[Test]
    public function wireRegistersErrorTrackingWhenEnabled(): void
    {
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManager(enabled: true);
        $configManager->load();

        $wiring = new ErrorTrackingWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        self::assertTrue($container->has(SensitiveDataScrubber::class));
        self::assertTrue($container->has(ErrorAggregator::class));
        self::assertTrue($container->has(ErrorAggregatorInterface::class));
    }

    #[Test]
    public function wireSkipsRegistrationWhenDisabled(): void
    {
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManager(enabled: false);
        $configManager->load();

        $wiring = new ErrorTrackingWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        self::assertFalse($container->has(SensitiveDataScrubber::class));
        self::assertFalse($container->has(ErrorAggregator::class));
    }

    private function createConfigManager(bool $enabled): ConfigManager
    {
        $configPath = sys_get_temp_dir() . '/pulsar_error_tracking_' . bin2hex(random_bytes(4));
        @mkdir($configPath, 0o755, true);

        file_put_contents($configPath . '/app.php', '<?php return ["name" => "Test", "env" => "testing", "debug" => false, "timezone" => "UTC", "locale" => "en"];');
        $enabledStr = $enabled ? 'true' : 'false';
        file_put_contents($configPath . '/observability.php', '<?php return ["logging" => ["default_channel" => "file", "level" => "debug", "channels" => []], "error_tracking" => ["enabled" => ' . $enabledStr . ']];');
        file_put_contents($configPath . '/security.php', '<?php return ["session" => [], "csrf" => [], "headers" => [], "rate_limit" => []];');

        return new ConfigManager($configPath);
    }
}
