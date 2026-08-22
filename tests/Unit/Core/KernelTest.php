<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\ConfigManager;
use Pulsar\Container\ContainerInterface;
use Pulsar\Core\Kernel;
use Pulsar\Routing\Router;

#[CoversClass(Kernel::class)]
final class KernelTest extends TestCase
{
    #[Test]
    public function kernelIsNotBootedByDefault(): void
    {
        $kernel = new Kernel();

        self::assertFalse($kernel->booted);
    }

    #[Test]
    public function kernelCanBoot(): void
    {
        $kernel = new Kernel();
        $kernel->boot();

        self::assertTrue($kernel->booted);
    }

    #[Test]
    public function kernelBootIsIdempotent(): void
    {
        $kernel = new Kernel();
        $kernel->boot();
        $kernel->boot();

        self::assertTrue($kernel->booted);
    }

    #[Test]
    public function kernelCanShutdown(): void
    {
        $kernel = new Kernel();
        $kernel->boot();
        $kernel->shutdown();

        self::assertFalse($kernel->booted);
    }

    #[Test]
    public function kernelProvidesContainer(): void
    {
        $kernel = new Kernel();

        self::assertInstanceOf(ContainerInterface::class, $kernel->container());
    }

    #[Test]
    public function kernelProvidesRouter(): void
    {
        $kernel = new Kernel();

        self::assertInstanceOf(Router::class, $kernel->router());
    }

    #[Test]
    public function kernelRegistersItselfInContainer(): void
    {
        $kernel = new Kernel();

        self::assertSame($kernel, $kernel->container()->get(Kernel::class));
    }

    #[Test]
    public function kernelRegistersRouterInContainer(): void
    {
        $kernel = new Kernel();

        self::assertSame($kernel->router(), $kernel->container()->get(Router::class));
    }

    #[Test]
    public function kernelAcceptsCustomContainer(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(false);
        $container->expects($this->atLeast(3))->method('instance');

        $kernel = new Kernel($container);

        self::assertSame($container, $kernel->container());
    }

    #[Test]
    public function kernelAcceptsCustomRouter(): void
    {
        $router = new Router();

        $kernel = new Kernel(router: $router);

        self::assertSame($router, $kernel->router());
    }

    #[Test]
    public function kernelLoadsProjectRouteFiles(): void
    {
        $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_kernel_routes_' . bin2hex(random_bytes(8));
        $configDir = $tempDir . DIRECTORY_SEPARATOR . 'config';
        $routesDir = $tempDir . DIRECTORY_SEPARATOR . 'routes';

        mkdir($configDir, 0o755, true);
        mkdir($routesDir, 0o755, true);

        // Minimal config files required by ConfigManager
        file_put_contents($configDir . '/app.php', '<?php return ["name" => "Test", "env" => "testing", "debug" => false, "timezone" => "UTC", "locale" => "en"];');
        file_put_contents($configDir . '/observability.php', '<?php return ["logging" => ["default_channel" => "file", "level" => "debug", "channels" => []]];');
        file_put_contents($configDir . '/security.php', '<?php return ["session" => [], "csrf" => [], "headers" => [], "rate_limit" => []];');

        // Route file that registers a custom route
        file_put_contents($routesDir . '/web.php', '<?php $router->get("/custom-route", fn() => "ok", "custom.route");');

        $configManager = new ConfigManager($configDir);
        $kernel = new Kernel(configManager: $configManager);
        $kernel->boot();

        $router = $kernel->router();
        self::assertInstanceOf(Router::class, $router);
        /** @var Router $router */
        $routes = $router->routes();

        $routeNames = array_map(static fn($r) => $r->name, $routes);
        self::assertContains('custom.route', $routeNames);

        // Cleanup
        $this->cleanupTempDir($tempDir);
    }

    #[Test]
    public function kernelSkipsRouteFilesWhenNoneExist(): void
    {
        $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_kernel_noroutes_' . bin2hex(random_bytes(8));
        $configDir = $tempDir . DIRECTORY_SEPARATOR . 'config';

        mkdir($configDir, 0o755, true);

        file_put_contents($configDir . '/app.php', '<?php return ["name" => "Test", "env" => "testing", "debug" => false, "timezone" => "UTC", "locale" => "en"];');
        file_put_contents($configDir . '/observability.php', '<?php return ["logging" => ["default_channel" => "file", "level" => "debug", "channels" => []]];');
        file_put_contents($configDir . '/security.php', '<?php return ["session" => [], "csrf" => [], "headers" => [], "rate_limit" => []];');

        $configManager = new ConfigManager($configDir);
        $kernel = new Kernel(configManager: $configManager);

        // Should boot successfully without a routes/ directory
        $kernel->boot();
        self::assertTrue($kernel->booted);

        $this->cleanupTempDir($tempDir);
    }

    private function cleanupTempDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $entries = scandir($path);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $full = $path . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($full)) {
                $this->cleanupTempDir($full);
            } else {
                // nosemgrep: php.lang.security.unlink-use.unlink-use
                unlink($full);
            }
        }

        rmdir($path);
    }
}
