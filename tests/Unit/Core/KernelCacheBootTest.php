<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\CachedBinding;
use Pulsar\Cache\CachedRoute;
use Pulsar\Cache\CacheManifest;
use Pulsar\Cache\FrameworkCache;
use Pulsar\Cache\FrameworkCacheInterface;
use Pulsar\Cache\RouteHandler;
use Pulsar\Cache\RouteHandlerType;
use Pulsar\Config\ConfigManager;
use Pulsar\Container\Container;
use Pulsar\Core\Kernel;
use Pulsar\Http\Method;
use Pulsar\Routing\Router;
use Pulsar\Routing\RoutingException;

use function bin2hex;
use function count;
use function file_put_contents;
use function is_array;
use function is_dir;
use function is_string;
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

    /**
     * A strict route cache is what `pulsar optimize --strict` wrote, so the
     * table it holds is the one a cold boot of this same application produced.
     * The fixture is built that way — cold boot, capture, replay — rather than
     * from a synthetic route, because the thing under test is what happens when
     * the wirings register their routes a second time against the locked
     * router. A hand-written table of one route cannot exercise that.
     */
    #[Test]
    public function strictCacheModeLocksTheRouterAndStillBoots(): void
    {
        $cachedRoutes = [
            ...$this->routesAColdBootProduces(),
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

        self::assertTrue($kernel->booted);
        self::assertTrue($router->locked);
        self::assertNotNull($router->getByName('strict.index'));
    }

    /**
     * The boot-time registrations are replays of the cached table, so they must
     * leave it exactly as the cache stated it. Appending them would grow the
     * table on every boot of a long-running SAPI and reorder first-registered-
     * wins matching.
     */
    #[Test]
    public function theWiringsReplayingTheirRoutesDoNotGrowTheCachedTable(): void
    {
        $cachedRoutes = [
            ...$this->routesAColdBootProduces(),
            new CachedRoute(
                methods: [Method::GET],
                path: '/strict-cached',
                handler: new RouteHandler(RouteHandlerType::Invokable, self::class),
                name: 'strict.index',
            ),
        ];

        $cache = $this->createFakeCache($this->createManifest(strict: true), $cachedRoutes);

        $container = new Container();
        $container->instance(FrameworkCache::class, $cache);

        $router = new Router();

        new Kernel(
            container: $container,
            router: $router,
            configManager: new ConfigManager($this->configPath),
        )->boot();

        self::assertCount(count($cachedRoutes), $router->routes());
        self::assertSame([], $router->collisions);
    }

    /**
     * The lock is not decoration: a deployment whose code registers a route its
     * strict cache never carried has drifted from the table it booted from, and
     * serving the difference would serve a route nothing verified.
     */
    #[Test]
    public function aRouteMissingFromTheStrictCacheStopsTheBoot(): void
    {
        // The cold-boot routes are deliberately NOT included, so every route the
        // wirings register is one this cache does not hold.
        $cachedRoutes = [
            new CachedRoute(
                methods: [Method::GET],
                path: '/strict-cached',
                handler: new RouteHandler(RouteHandlerType::Invokable, self::class),
                name: 'strict.index',
            ),
        ];

        $container = new Container();
        $container->instance(
            FrameworkCache::class,
            $this->createFakeCache($this->createManifest(strict: true), $cachedRoutes),
        );

        $kernel = new Kernel(
            container: $container,
            router: new Router(),
            configManager: new ConfigManager($this->configPath),
        );

        $this->expectException(RoutingException::class);
        $this->expectExceptionCode(423);

        $kernel->boot();
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

    /**
     * A manifest may say `strict` while carrying no route table; the lock
     * follows the table, not the flag, because there is nothing authoritative
     * to lock onto. The boot is then an ordinary cold boot, and the assertion
     * is that it registered what a cold boot registers — this used to assert an
     * empty router, which was really asserting that the kernel skipped a wiring
     * under a strict manifest.
     */
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
        self::assertSame(count($this->routesAColdBootProduces()), $router->count());
    }

    #[Test]
    public function aCachePayloadWithoutTheBindingsKeyBootsInsteadOfFataling(): void
    {
        // FrameworkCacheInterface is public API stamped `since: 1.0.0`, and
        // `bindings` was added to its load() shape afterwards. An implementation
        // written against the published contract cannot supply the key — and it
        // reaches this code, because preBindFrameworkCache() yields to any
        // FrameworkCache already bound in the container.
        //
        // Reading the key as guaranteed made that an undefined-key warning and
        // then a TypeError, thrown inside boot() and caught by handle()'s
        // catch(Throwable): a 500 on every request of an application that was
        // working before it deployed this version.
        $cache = new class ($this->createManifest(strict: false)) {
            public function __construct(private readonly CacheManifest $manifest) {}

            /**
             * @return array{manifest: CacheManifest, config: null, routes: list<CachedRoute>, containerHints: null}
             */
            public function load(string $configPath): array
            {
                return [
                    'manifest' => $this->manifest,
                    'config' => null,
                    'routes' => [
                        new CachedRoute(
                            methods: [Method::GET],
                            path: '/legacy-cached',
                            handler: new RouteHandler(RouteHandlerType::Invokable, KernelCacheBootTest::class),
                            name: 'legacy.index',
                        ),
                    ],
                    'containerHints' => null,
                ];
            }
        };

        $container = new Container();
        $container->instance(FrameworkCache::class, $cache);

        $router = new Router();
        $kernel = new Kernel(
            container: $container,
            router: $router,
            configManager: new ConfigManager($this->configPath),
        );

        $kernel->boot();

        self::assertTrue($kernel->booted);

        // And it boots COLD. A payload that cannot state its binding
        // declarations is a payload whose route table this boot cannot vouch
        // for — the same answer a self-contradictory declaration already gets,
        // for the same reason. Serving the routes without the declarations that
        // qualify them is the defect the bindings key was added to close, so
        // falling back to it on a missing key would be that defect again.
        self::assertNull($router->getByName('legacy.index'));
        self::assertFalse($router->locked);
    }

    /**
     * The route table a cold boot of this fixture builds, in the cache's own
     * shape — the same conversion {@see \Pulsar\Cache\RouteCache::compile()}
     * performs when `pulsar optimize` writes one.
     *
     * Deriving the fixture from a real boot is what keeps the strict-cache
     * tests honest: a hand-written table would let the boot-time registrations
     * disagree with it forever without any test noticing, which is the shape of
     * the defect these tests exist for.
     *
     * @return list<CachedRoute>
     */
    private function routesAColdBootProduces(): array
    {
        $router = new Router();

        new Kernel(
            container: new Container(),
            router: $router,
            configManager: new ConfigManager($this->configPath),
        )->boot();

        $cached = [];

        foreach ($router->routes() as $route) {
            /** @var mixed $handler */
            $handler = $route->handler;

            $normalized = match (true) {
                is_string($handler) => new RouteHandler(RouteHandlerType::Invokable, $handler),
                is_array($handler) && isset($handler[0], $handler[1])
                    && is_string($handler[0]) && is_string($handler[1])
                    => new RouteHandler(RouteHandlerType::Method, $handler[0], $handler[1]),
                default => null,
            };

            // Closure handlers are exactly what `--strict` refuses to cache, so
            // a strict table never contains one.
            if ($normalized === null) {
                continue;
            }

            $cached[] = new CachedRoute(
                methods: $route->methods,
                path: $route->path,
                handler: $normalized,
                name: $route->name,
                attributes: $route->attributes,
                middleware: $route->middleware,
                constraints: $route->constraints,
                host: $route->host,
            );
        }

        return $cached;
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
             * The `bindings` key is not optional. {@see \Pulsar\Core\Kernel}
             * reads it in the same breath as `routes`, because a cached route
             * table without the declarations that qualify it would boot an
             * application whose binding scopes had evaporated while the routes
             * they qualify were still served. A payload omitting it is not a
             * smaller payload; it is a payload the kernel cannot vouch for.
             *
             * @return array{
             *     manifest: CacheManifest,
             *     config: null,
             *     routes: list<CachedRoute>|null,
             *     bindings: list<CachedBinding>,
             *     containerHints: null,
             * }
             */
            public function load(string $configPath): array
            {
                return [
                    'manifest' => $this->manifest,
                    'config' => null,
                    'routes' => $this->routes !== [] ? $this->routes : null,
                    'bindings' => [],
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

    // -----------------------------------------------------------------
    // An implementation the framework did not write
    // -----------------------------------------------------------------

    /**
     * The published contract is actually consulted.
     *
     * Every read on the cache-load path used to key on `FrameworkCache::class`,
     * which is `final` — so a third party could not supply that binding at all,
     * and an application binding its own implementation of the stable
     * `#[Api(since: '1.0.0')]` interface had it silently ignored. Worse,
     * `preBindFrameworkCache()` guarded on the same concrete id, so with a master
     * key present it constructed the framework's own cache and OVERWROTE the
     * interface binding. The only supported way to supply an implementation was
     * quietly undone.
     */
    #[Test]
    public function aThirdPartyCacheImplementationIsAskedAndNotReplaced(): void
    {
        $cache = new ProbeForeignFrameworkCache(null);

        $container = new Container();
        $container->instance(FrameworkCacheInterface::class, $cache);

        $kernel = new Kernel(
            container: $container,
            router: new Router(),
            configManager: new ConfigManager($this->configPath),
        );

        $kernel->boot();

        self::assertSame(1, $cache->loadCalls, 'the interface binding was never consulted');
        self::assertSame(
            $cache,
            $container->get(FrameworkCacheInterface::class),
            'the pre-bind replaced an implementation the application supplied',
        );
    }

    /**
     * A payload in a shape the framework's own writer never produces boots cold
     * instead of exploding.
     *
     * Reading the declared shape off a foreign payload was an undefined-key
     * warning followed by a property read on a non-object, then a `TypeError` or
     * an `UnhandledMatchError` inside `boot()` — a 500 on every request. Each key
     * that fails its check is now treated as absent, which is the cold path the
     * boot would have taken with no cache at all.
     */
    #[Test]
    public function aPayloadTheKernelCannotReadLeavesTheRouteTableAlone(): void
    {
        $cache = new ProbeForeignFrameworkCache([
            'routes' => [['methods' => ['GET'], 'path' => '/cached', 'handler' => ['x', 'y']]],
            'bindings' => [['parameter' => null, 'modelClass' => 'X']],
            'containerHints' => ['App\\Thing' => 'not-a-list'],
        ]);

        $container = new Container();
        $container->instance(FrameworkCacheInterface::class, $cache);

        $router = new Router();
        $kernel = new Kernel(
            container: $container,
            router: $router,
            configManager: new ConfigManager($this->configPath),
        );

        $kernel->boot();

        self::assertSame(1, $cache->loadCalls);
        self::assertNull($router->getByName('cached.index'));
        self::assertFalse($router->locked);
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

/**
 * An application's own implementation of the published cache contract, which is
 * the only thing {@see FrameworkCacheInterface} being stable API can mean. It
 * records whether the kernel asked it anything, and returns a payload in a shape
 * the framework's own writer never produces.
 */
final class ProbeForeignFrameworkCache implements FrameworkCacheInterface
{
    public int $loadCalls = 0;

    /** @param array<string, mixed>|null $payload */
    public function __construct(private readonly ?array $payload) {}

    public function warm(
        \Pulsar\Config\ConfigRepository $repository,
        array $routes,
        array $containerHints,
        string $appEnv,
        bool $strict,
        array $bindings = [],
    ): array {
        return [
            'configCached' => false,
            'routesCached' => 0,
            'routesSkipped' => 0,
            'skippedRoutes' => [],
            'containerCached' => false,
        ];
    }

    public function clear(): void {}

    public function isWarm(): bool
    {
        return $this->payload !== null;
    }

    public function load(string $configPath): ?array
    {
        ++$this->loadCalls;

        /** @var array{manifest: CacheManifest, config: null, routes: null, bindings: list<CachedBinding>, containerHints: null}|null */
        return $this->payload;
    }

    public function cachePath(): string
    {
        return sys_get_temp_dir();
    }

    public function computeInvalidationKey(string $configPath): string
    {
        return 'probe';
    }
}
