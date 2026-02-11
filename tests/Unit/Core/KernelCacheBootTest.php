<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\CachedRoute;
use Pulsar\Cache\CacheManifest;
use Pulsar\Cache\FrameworkCache;
use Pulsar\Cache\RouteHandler;
use Pulsar\Cache\RouteHandlerType;
use Pulsar\Config\ConfigManager;
use Pulsar\Container\Container;
use Pulsar\Core\Kernel;
use Pulsar\Http\Method;
use Pulsar\Routing\Router;

use function bin2hex;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function random_bytes;
use function scandir;

#[CoversClass(Kernel::class)]
final class KernelCacheBootTest extends TestCase
{
    private string $configPath;

    protected function setUp(): void
    {
        $this->configPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_kernel_cache_test_' . bin2hex(random_bytes(8));
        mkdir($this->configPath, 0o750, true);
        $this->writeConfigStubs($this->configPath);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->configPath);
    }

    #[Test]
    public function cachedRoutesAreLoadedIntoRouterOnBoot(): void
    {
        $cachedRoutes = [
            new CachedRoute(
                methods: [Method::GET],
                path: '/cached',
                handler: new RouteHandler(RouteHandlerType::Invokable, self::class),
                name: 'cached.index',
            ),
        ];

        $manifest = $this->createManifest(strict: false);
        $cache = $this->createFakeCache($manifest, $cachedRoutes);

        $container = new Container();
        $container->instance(FrameworkCache::class, $cache);

        $router = new Router();
        $configManager = new ConfigManager($this->configPath);

        $kernel = new Kernel(
            container: $container,
            router: $router,
            configManager: $configManager,
        );

        $kernel->boot();

        self::assertNotNull($router->getByName('cached.index'));
        self::assertSame('/cached', $router->getByName('cached.index')->path);
        self::assertFalse($router->locked);
    }

    #[Test]
    public function strictCacheModeLockRouter(): void
    {
        $cachedRoutes = [
            new CachedRoute(
                methods: [Method::GET],
                path: '/strict-cached',
                handler: new RouteHandler(RouteHandlerType::Invokable, self::class),
                name: 'strict.index',
            ),
        ];

        $manifest = $this->createManifest(strict: true);
        $cache = $this->createFakeCache($manifest, $cachedRoutes);

        $container = new Container();
        $container->instance(FrameworkCache::class, $cache);

        $router = new Router();
        $configManager = new ConfigManager($this->configPath);

        $kernel = new Kernel(
            container: $container,
            router: $router,
            configManager: $configManager,
        );

        $kernel->boot();

        self::assertTrue($router->locked);
        self::assertNotNull($router->getByName('strict.index'));
    }

    #[Test]
    public function cacheMissFallsThroughToNormalBoot(): void
    {
        $container = new Container();
        $router = new Router();
        $configManager = new ConfigManager($this->configPath);

        $kernel = new Kernel(
            container: $container,
            router: $router,
            configManager: $configManager,
        );

        $kernel->boot();

        self::assertFalse($router->locked);
        self::assertTrue($kernel->booted);
    }

    #[Test]
    public function emptyRoutesCacheDoesNotLockRouter(): void
    {
        $manifest = $this->createManifest(strict: true);
        $cache = $this->createFakeCache($manifest, []);

        $container = new Container();
        $container->instance(FrameworkCache::class, $cache);

        $router = new Router();
        $configManager = new ConfigManager($this->configPath);

        $kernel = new Kernel(
            container: $container,
            router: $router,
            configManager: $configManager,
        );

        $kernel->boot();

        self::assertFalse($router->locked);
        self::assertSame(0, $router->count());
    }

    /**
     * @param list<CachedRoute> $routes
     */
    private function createFakeCache(CacheManifest $manifest, array $routes): object
    {
        return new class ($manifest, $routes) {
            /**
             * @param list<CachedRoute> $routes
             */
            public function __construct(
                private readonly CacheManifest $manifest,
                private readonly array $routes,
            ) {}

            /**
             * @return array{manifest: CacheManifest, config: null, routes: list<CachedRoute>|null, containerHints: null}
             */
            public function load(string $configPath): array
            {
                return [
                    'manifest' => $this->manifest,
                    'config' => null,
                    'routes' => $this->routes !== [] ? $this->routes : null,
                    'containerHints' => null,
                ];
            }
        };
    }

    private function createManifest(bool $strict): CacheManifest
    {
        return new CacheManifest(
            schemaVersion: 1,
            frameworkVersion: '1.0.0-rc.1',
            appEnv: 'testing',
            generatedAt: time(),
            invalidationKey: 'test',
            allowedClassesHash: 'test',
            caches: [],
            strict: $strict,
            encrypted: false,
        );
    }

    private function writeConfigStubs(string $configPath): void
    {
        file_put_contents(
            $configPath . DIRECTORY_SEPARATOR . 'app.php',
            "<?php\nreturn ['name' => 'test', 'env' => 'testing', 'debug' => true, 'timezone' => 'UTC', 'locale' => 'en'];\n",
        );
        file_put_contents(
            $configPath . DIRECTORY_SEPARATOR . 'observability.php',
            "<?php\nreturn ['logging' => ['default_channel' => 'file', 'level' => 'info', 'channels' => []], 'metrics' => ['enabled' => false, 'exporters' => []], 'tracing' => ['enabled' => false, 'sampling_rate' => 0.0], 'error_tracking' => ['enabled' => false, 'max_groups' => 100, 'max_recent_events_per_group' => 5, 'sensitive_fields' => []], 'audit' => ['enabled' => false, 'log_path' => '/dev/null', 'events' => []]];\n",
        );
        file_put_contents(
            $configPath . DIRECTORY_SEPARATOR . 'security.php',
            "<?php\nreturn ['session' => ['cookie_name' => 'TEST', 'lifetime' => 3600, 'cookie_httponly' => true, 'cookie_secure' => false, 'cookie_samesite' => 'Lax', 'regenerate_on_privilege_change' => true], 'csrf' => ['enabled' => false, 'token_length' => 32, 'header_name' => 'X-CSRF-Token', 'form_field_name' => '_csrf'], 'headers' => [], 'rate_limiting' => ['enabled' => false, 'default_limit' => 60, 'default_window' => 60], 'auth' => ['default_guard' => 'session', 'guards' => [], 'two_factor' => ['enabled' => false, 'issuer' => 'Test', 'code_digits' => 6, 'code_period' => 30, 'verification_window' => 1, 'recovery_code_count' => 8], 'authorization' => ['roles' => [], 'super_roles' => []]]];\n",
        );
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $item;

            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
