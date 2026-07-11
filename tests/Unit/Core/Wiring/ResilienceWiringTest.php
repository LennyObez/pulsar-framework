<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core\Wiring;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\CircuitBreakerConfig;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\HealthCheckConfig;
use Pulsar\Config\ResilienceConfig;
use Pulsar\Config\RetryConfig;
use Pulsar\Container\Container;
use Pulsar\Core\Wiring\ResilienceWiring;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Resilience\CircuitBreakerRegistry;
use Pulsar\Resilience\HealthCheck\HealthCheckRunner;
use Pulsar\Resilience\HealthCheck\HealthCheckRunnerInterface;
use Pulsar\Resilience\Repair\RepairRunner;
use Pulsar\Resilience\Repair\RepairRunnerInterface;
use Pulsar\Resilience\RetryPolicy;
use Pulsar\Routing\Router;
use Pulsar\Security\Crypto\FipsComplianceCheck;

use function bin2hex;
use function file_put_contents;
use function mkdir;
use function random_bytes;

#[CoversClass(ResilienceWiring::class)]
final class ResilienceWiringTest extends TestCase
{
    #[Test]
    public function wireRegistersResilienceServicesWhenEnabled(): void
    {
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManager(enabled: true);
        $configManager->load();

        $wiring = new ResilienceWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        self::assertTrue($container->has(ResilienceConfig::class));
        self::assertTrue($container->has(RetryConfig::class));
        self::assertTrue($container->has(CircuitBreakerConfig::class));
        self::assertTrue($container->has(HealthCheckConfig::class));
        self::assertTrue($container->has(RetryPolicy::class));
        self::assertTrue($container->has(CircuitBreakerRegistry::class));
        self::assertTrue($container->has(HealthCheckRunner::class));
        self::assertTrue($container->has(HealthCheckRunnerInterface::class));
        self::assertTrue($container->has(RepairRunner::class));
        self::assertTrue($container->has(RepairRunnerInterface::class));

        // Regression lock: the FIPS check reports "degraded" on non-FIPS hosts
        // and /health 503s on it, so it must NEVER register by default.
        /** @var HealthCheckRunner $runner */
        $runner = $container->get(HealthCheckRunner::class);
        self::assertNotContains('fips_compliance', $runner->names());
        self::assertNotContains('fips', $runner->names());
    }

    #[Test]
    public function wireRegistersTheFipsCheckOnlyWhenOptedIn(): void
    {
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManager(enabled: true, extra: ', "health_check" => ["fips_check" => true]');
        $configManager->load();

        $wiring = new ResilienceWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        /** @var HealthCheckRunner $runner */
        $runner = $container->get(HealthCheckRunner::class);
        self::assertContains(new FipsComplianceCheck()->getName(), $runner->names());
    }

    #[Test]
    public function wireRegistersConfigButNotServicesWhenDisabled(): void
    {
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManager(enabled: false);
        $configManager->load();

        $wiring = new ResilienceWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        self::assertTrue($container->has(ResilienceConfig::class));
        self::assertTrue($container->has(RetryConfig::class));
        self::assertFalse($container->has(RetryPolicy::class));
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

        $wiring = new ResilienceWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        self::assertFalse($container->has(ResilienceConfig::class));
    }

    private function createConfigManager(bool $enabled, string $extra = ''): ConfigManager
    {
        $configPath = sys_get_temp_dir() . '/pulsar_resilience_wiring_' . bin2hex(random_bytes(4));
        @mkdir($configPath, 0o755, true);

        $enabledStr = $enabled ? 'true' : 'false';
        file_put_contents($configPath . '/app.php', '<?php return ["name" => "Test", "env" => "testing", "debug" => false, "timezone" => "UTC", "locale" => "en"];');
        file_put_contents($configPath . '/observability.php', '<?php return ["logging" => ["default_channel" => "file", "level" => "debug", "channels" => []]];');
        file_put_contents($configPath . '/security.php', '<?php return ["session" => [], "csrf" => [], "headers" => [], "rate_limit" => []];');
        file_put_contents($configPath . '/resilience.php', '<?php return ["enabled" => ' . $enabledStr . $extra . '];');

        return new ConfigManager($configPath);
    }

    private function createConfigManagerWithout(): ConfigManager
    {
        $configPath = sys_get_temp_dir() . '/pulsar_resilience_wiring_no_' . bin2hex(random_bytes(4));
        @mkdir($configPath, 0o755, true);

        file_put_contents($configPath . '/app.php', '<?php return ["name" => "Test", "env" => "testing", "debug" => false, "timezone" => "UTC", "locale" => "en"];');
        file_put_contents($configPath . '/observability.php', '<?php return ["logging" => ["default_channel" => "file", "level" => "debug", "channels" => []]];');
        file_put_contents($configPath . '/security.php', '<?php return ["session" => [], "csrf" => [], "headers" => [], "rate_limit" => []];');

        return new ConfigManager($configPath);
    }
}
