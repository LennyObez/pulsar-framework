<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\ConfigManager;
use Pulsar\Core\Kernel;
use Pulsar\Http\Message\ServerRequest;

use function file_put_contents;
use function is_dir;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function unlink;

use const DIRECTORY_SEPARATOR;

/**
 * Tests that the Kernel's loadProjectRouteFiles method supports both
 * direct router usage in route files and route files that return a closure.
 */
#[CoversClass(Kernel::class)]
final class KernelRouteClosureTest extends TestCase
{
    private string $tempDir;
    private string $configDir;
    private string $routesDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_kernel_route_test_' . bin2hex(random_bytes(4));
        $this->configDir = $this->tempDir . DIRECTORY_SEPARATOR . 'config';
        $this->routesDir = $this->tempDir . DIRECTORY_SEPARATOR . 'routes';

        mkdir($this->configDir, 0o755, true);
        mkdir($this->routesDir, 0o755, true);

        // Kernel::boot() requires these config files
        file_put_contents($this->configDir . DIRECTORY_SEPARATOR . 'app.php', "<?php\nreturn ['name' => 'test', 'env' => 'local', 'debug' => true];\n");
        file_put_contents($this->configDir . DIRECTORY_SEPARATOR . 'security.php', "<?php\nreturn [];\n");
        file_put_contents($this->configDir . DIRECTORY_SEPARATOR . 'observability.php', "<?php\nreturn [];\n");
    }

    protected function tearDown(): void
    {
        // Clean up route files
        foreach (['web.php', 'api.php'] as $file) {
            $routePath = $this->routesDir . DIRECTORY_SEPARATOR . $file;
            if (file_exists($routePath)) {
                unlink($routePath); // nosemgrep: php.lang.security.unlink-use.unlink-use
            }
        }

        // Clean up config files
        foreach (['app.php', 'security.php', 'observability.php'] as $file) {
            $configPath = $this->configDir . DIRECTORY_SEPARATOR . $file;
            if (file_exists($configPath)) {
                unlink($configPath); // nosemgrep: php.lang.security.unlink-use.unlink-use
            }
        }

        if (is_dir($this->routesDir)) {
            rmdir($this->routesDir);
        }

        if (is_dir($this->configDir)) {
            rmdir($this->configDir);
        }

        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
    }

    #[Test]
    public function routeFileWithDirectRouterUsageRegistersRoutes(): void
    {
        // Route file that uses $router directly (traditional style)
        $routeCode = <<<'PHP'
            <?php
            $router->get('/direct-route', fn() => \Pulsar\Http\Message\Response::text('direct'));
            PHP;

        file_put_contents($this->routesDir . DIRECTORY_SEPARATOR . 'web.php', $routeCode);

        $configManager = new ConfigManager($this->configDir);
        $kernel = new Kernel(configManager: $configManager);
        $kernel->boot();

        $request = new ServerRequest(method: 'GET', uri: '/direct-route');
        $response = $kernel->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('direct', (string) $response->getBody());
    }

    #[Test]
    public function routeFileReturningClosureInvokesItWithRouter(): void
    {
        // Route file that returns a closure (modern style)
        $routeCode = <<<'PHP'
            <?php
            return function (\Pulsar\Routing\Router $router): void {
                $router->get('/closure-route', fn() => \Pulsar\Http\Message\Response::text('closure'));
            };
            PHP;

        file_put_contents($this->routesDir . DIRECTORY_SEPARATOR . 'web.php', $routeCode);

        $configManager = new ConfigManager($this->configDir);
        $kernel = new Kernel(configManager: $configManager);
        $kernel->boot();

        $request = new ServerRequest(method: 'GET', uri: '/closure-route');
        $response = $kernel->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('closure', (string) $response->getBody());
    }

    #[Test]
    public function routeFileReturningNonClosureIsIgnored(): void
    {
        // Route file that returns a non-closure value (should not crash)
        $routeCode = <<<'PHP'
            <?php
            $router->get('/non-closure-test', fn() => \Pulsar\Http\Message\Response::text('works'));
            return 42; // Non-closure return
            PHP;

        file_put_contents($this->routesDir . DIRECTORY_SEPARATOR . 'web.php', $routeCode);

        $configManager = new ConfigManager($this->configDir);
        $kernel = new Kernel(configManager: $configManager);
        $kernel->boot();

        // The direct-registered route should still work
        $request = new ServerRequest(method: 'GET', uri: '/non-closure-test');
        $response = $kernel->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('works', (string) $response->getBody());
    }

    #[Test]
    public function bothRouteFilesAreLoaded(): void
    {
        $webCode = <<<'PHP'
            <?php
            $router->get('/from-web', fn() => \Pulsar\Http\Message\Response::text('web'));
            PHP;

        $apiCode = <<<'PHP'
            <?php
            return function (\Pulsar\Routing\Router $router): void {
                $router->get('/from-api', fn() => \Pulsar\Http\Message\Response::text('api'));
            };
            PHP;

        file_put_contents($this->routesDir . DIRECTORY_SEPARATOR . 'web.php', $webCode);
        file_put_contents($this->routesDir . DIRECTORY_SEPARATOR . 'api.php', $apiCode);

        $configManager = new ConfigManager($this->configDir);
        $kernel = new Kernel(configManager: $configManager);
        $kernel->boot();

        $webRequest = new ServerRequest(method: 'GET', uri: '/from-web');
        $webResponse = $kernel->handle($webRequest);
        self::assertSame('web', (string) $webResponse->getBody());

        $apiRequest = new ServerRequest(method: 'GET', uri: '/from-api');
        $apiResponse = $kernel->handle($apiRequest);
        self::assertSame('api', (string) $apiResponse->getBody());
    }
}
