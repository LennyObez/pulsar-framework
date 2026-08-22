<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core\Wiring;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\TenancyConfig;
use Pulsar\Container\Container;
use Pulsar\Core\Wiring\TenancyWiring;
use Pulsar\Database\ConnectionManagerInterface;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\Router;
use Pulsar\Tenancy\Middleware\TenantResolutionMiddleware;
use Pulsar\Tenancy\TenantAwareConnectionManager;
use Pulsar\Tenancy\TenantContext;
use Pulsar\Tenancy\TenantResolverInterface;

use function bin2hex;
use function file_put_contents;
use function mkdir;
use function random_bytes;

#[CoversClass(TenancyWiring::class)]
final class TenancyWiringTest extends TestCase
{
    #[Test]
    public function wireRegistersTenancyServicesWhenEnabled(): void
    {
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManager(enabled: true, resolver: 'header');
        $configManager->load();

        $wiring = new TenancyWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        self::assertTrue($container->has(TenancyConfig::class));
        self::assertTrue($container->has(TenantContext::class));
        self::assertTrue($container->has(TenantResolverInterface::class));
        self::assertTrue($container->has(TenantResolutionMiddleware::class));
    }

    /**
     * The container assertion above is what let the defect live: it proves the
     * middleware was built, which is not the same as proving it runs. The wiring
     * bound it and never piped it, so TenantContext stayed empty on every request
     * while five green assertions said tenancy was configured.
     */
    #[Test]
    public function wirePipesTenantResolutionSoTheContextIsActuallyPopulated(): void
    {
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManager(enabled: true, resolver: 'header');
        $configManager->load();

        $wiring = new TenancyWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        $piped = array_filter(
            $middleware->snapshot(),
            static fn(mixed $entry): bool => $entry instanceof TenantResolutionMiddleware,
        );

        self::assertCount(
            1,
            $piped,
            'Tenant resolution must be in the request pipeline, not merely in the container.',
        );
    }

    #[Test]
    public function wirePipesNothingWhenTenancyIsDisabled(): void
    {
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManager(enabled: false, resolver: 'header');
        $configManager->load();

        new TenancyWiring()->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        self::assertTrue($middleware->isEmpty());
    }

    #[Test]
    public function wireRebindsConnectionManagerInterfaceToTenantAwareDecorator(): void
    {
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        // DatabaseWiring binds the base manager first.
        $inner = $this->createStub(ConnectionManagerInterface::class);
        $container->instance(ConnectionManagerInterface::class, $inner);

        $configManager = $this->createConfigManager(enabled: true, resolver: 'header');
        $configManager->load();

        $wiring = new TenancyWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        // Regression: the interface must now resolve to the tenant-aware
        // decorator, not the inner manager — otherwise the decorator is built
        // but never used and every consumer routes to the default connection.
        $resolved = $container->get(ConnectionManagerInterface::class);
        self::assertInstanceOf(TenantAwareConnectionManager::class, $resolved);
        self::assertNotSame($inner, $resolved);
    }

    #[Test]
    public function wireLeavesConnectionManagerUnboundWhenNoneRegistered(): void
    {
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManager(enabled: true, resolver: 'header');
        $configManager->load();

        $wiring = new TenancyWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        // No base manager present (no DatabaseWiring): nothing to decorate, so
        // the interface stays unbound rather than failing.
        self::assertFalse($container->has(ConnectionManagerInterface::class));
        self::assertFalse($container->has(TenantAwareConnectionManager::class));
    }

    #[Test]
    public function wireSkipsWhenDisabled(): void
    {
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManager(enabled: false, resolver: 'header');
        $configManager->load();

        $wiring = new TenancyWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        self::assertTrue($container->has(TenancyConfig::class));
        self::assertFalse($container->has(TenantContext::class));
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

        $wiring = new TenancyWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        self::assertFalse($container->has(TenancyConfig::class));
    }

    private function createConfigManager(bool $enabled, string $resolver): ConfigManager
    {
        $configPath = sys_get_temp_dir() . '/pulsar_tenancy_wiring_' . bin2hex(random_bytes(4));
        @mkdir($configPath, 0o755, true);

        $enabledStr = $enabled ? 'true' : 'false';
        file_put_contents($configPath . '/app.php', '<?php return ["name" => "Test", "env" => "testing", "debug" => false, "timezone" => "UTC", "locale" => "en"];');
        file_put_contents($configPath . '/observability.php', '<?php return ["logging" => ["default_channel" => "file", "level" => "debug", "channels" => []]];');
        file_put_contents($configPath . '/security.php', '<?php return ["session" => [], "csrf" => [], "headers" => [], "rate_limit" => []];');
        file_put_contents($configPath . '/tenancy.php', '<?php return ["enabled" => ' . $enabledStr . ', "resolver" => "' . $resolver . '"];');

        return new ConfigManager($configPath);
    }

    private function createConfigManagerWithout(): ConfigManager
    {
        $configPath = sys_get_temp_dir() . '/pulsar_tenancy_wiring_no_' . bin2hex(random_bytes(4));
        @mkdir($configPath, 0o755, true);

        file_put_contents($configPath . '/app.php', '<?php return ["name" => "Test", "env" => "testing", "debug" => false, "timezone" => "UTC", "locale" => "en"];');
        file_put_contents($configPath . '/observability.php', '<?php return ["logging" => ["default_channel" => "file", "level" => "debug", "channels" => []]];');
        file_put_contents($configPath . '/security.php', '<?php return ["session" => [], "csrf" => [], "headers" => [], "rate_limit" => []];');

        return new ConfigManager($configPath);
    }
}
