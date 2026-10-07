<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Cache;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\FrameworkCache;
use Pulsar\Config\AppConfig;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\ConfigRepository;
use Pulsar\Config\Environment;
use Pulsar\Config\EnvironmentMode;
use Pulsar\Core\Boot\CachedRouteReconstructor;
use Pulsar\Core\Kernel;
use Pulsar\Routing\Router;
use Pulsar\Security\Crypto\HmacService;
use Pulsar\Security\Crypto\MasterKey;

use function array_key_exists;
use function bin2hex;
use function getenv;
use function is_dir;
use function mkdir;
use function putenv;
use function random_bytes;
use function scandir;

#[CoversClass(Kernel::class)]
#[CoversClass(ConfigManager::class)]
final class CachedBootTest extends TestCase
{
    private string $basePath;

    /** @var array<string, string|false> Original env values to restore. */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        // realpath() first: these compare the base path the kernel derives, which is
        // resolved, against one built here — which a literal short form would not match.
        $temp = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();

        $this->basePath = $temp . DIRECTORY_SEPARATOR . 'pulsar_cached_boot_test_' . bin2hex(random_bytes(8));
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'config', 0o750, true);
        // FrameworkCache::warm() scans <base>/src to compute allowed classes.
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'src', 0o750, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $key => $original) {
            if ($original === false) {
                putenv($key);
            } else {
                putenv($key . '=' . $original);
            }
        }
        $this->savedEnv = [];

        $this->removeDirectory($this->basePath);
    }

    /**
     * Set (or, with null, unset) a process env var for one test, remembering the
     * original so tearDown restores it — env is process-global.
     */
    private function withEnv(string $key, ?string $value): void
    {
        if (!array_key_exists($key, $this->savedEnv)) {
            $this->savedEnv[$key] = getenv($key);
        }

        if ($value === null) {
            putenv($key);
        } else {
            putenv($key . '=' . $value);
        }
    }

    #[Test]
    public function it_uses_cached_config_when_framework_cache_is_available(): void
    {
        // 'CachedApp' appears nowhere on disk, so reading it back proves the
        // repository came from the cache rather than from the config files.
        $cachedRepo = new ConfigRepository();
        $cachedRepo->set(new AppConfig(
            name: 'CachedApp',
            mode: EnvironmentMode::Local,
            debug: true,
            timezone: 'UTC',
            locale: 'en',
        ));

        $configManager = new ConfigManager(
            configPath: $this->basePath . DIRECTORY_SEPARATOR . 'config',
        );

        $result = $configManager->loadFromCache($cachedRepo);

        self::assertTrue($result);
        self::assertSame($cachedRepo, $configManager->repository());
        self::assertSame('CachedApp', $configManager->repository()->get(AppConfig::class)->name);
    }

    #[Test]
    public function bootAnchorsBasePathToTheConfigParentWhenUnset(): void
    {
        // With PULSAR_BASE_PATH unset, boot() must export it from the config
        // directory's parent (the project root) so path helpers never fall back
        // to getcwd() — which under PHP-FPM is public/, and would put var/cache
        // and var/logs inside the webroot where anyone can fetch them.
        $configPath = $this->basePath . DIRECTORY_SEPARATOR . 'config';
        $this->writeMinimalConfigs($configPath);

        $this->withEnv('PULSAR_BASE_PATH', null);
        $this->withEnv('APP_ENV', 'local');

        $kernel = new Kernel(configManager: new ConfigManager(configPath: $configPath));
        $kernel->boot();

        self::assertSame($this->basePath, getenv('PULSAR_BASE_PATH'));
    }

    /**
     * The exported anchor must be a resolved path, not the caller's spelling of one.
     *
     * `dirname()` hands back whatever it was given, so a config path written with a
     * parent segment in it — `<root>/src/../config`, which is how a fixture laid
     * out relative to a helper file spells itself — used to export
     * `<root>/src/..` as the project root. Every path built on it then carried the
     * `..`, and both `MaintenanceMode` and `WritablePathGuard` refuse a storage path
     * containing one: the process poisoned itself with a traversal nobody wrote.
     *
     * That is not a hypothetical spelling. `tests/Benchmark/Support/BootBenchmarkKernel`
     * pointed at `__DIR__ . '/../Fixtures/BootProject/config'`, and the first kernel
     * booted from it after any test had cleared PULSAR_BASE_PATH took nine tests of
     * `BundledExtensionContractTest` down with it, in a different suite, with a
     * message naming a path that appears in no source file.
     */
    #[Test]
    public function bootAnchorsBasePathToAResolvedRootNotTheSpellingItWasGiven(): void
    {
        $configPath = $this->basePath . DIRECTORY_SEPARATOR . 'config';
        $this->writeMinimalConfigs($configPath);

        // Same directory, spelled through its sibling. setUp() created src/, so this
        // resolves; what matters is that the kernel is handed a traversal to export.
        $spelledWithATraversal = $this->basePath
            . DIRECTORY_SEPARATOR . 'src'
            . DIRECTORY_SEPARATOR . '..'
            . DIRECTORY_SEPARATOR . 'config';

        $this->withEnv('PULSAR_BASE_PATH', null);
        $this->withEnv('APP_ENV', 'local');

        $kernel = new Kernel(configManager: new ConfigManager(configPath: $spelledWithATraversal));
        $kernel->boot();

        $exported = getenv('PULSAR_BASE_PATH');

        self::assertIsString($exported, 'boot() exported no base path at all');
        self::assertStringNotContainsString(
            '..',
            $exported,
            'boot() exported a base path with a traversal segment still in it. Every path '
            . 'helper in the process now returns that traversal, and the guards that refuse '
            . 'one will refuse paths the deployment never wrote.',
        );
        self::assertSame($this->basePath, $exported);
    }

    #[Test]
    public function bootDoesNotOverrideAnExplicitBasePath(): void
    {
        $configPath = $this->basePath . DIRECTORY_SEPARATOR . 'config';
        $this->writeMinimalConfigs($configPath);

        // An operator override (FPM pool env / systemd) must win.
        //
        // Use a real, writable directory rather than an invented absolute path. Any
        // path proves the assertion, but boot builds var/logs beneath the base path:
        // an unwritable root leaves the logger erroring throughout the test. Pick one
        // that exists on every platform — POSIX refuses to create a new top-level
        // directory, while Windows treats a leading slash as drive-relative and
        // silently accepts it, so an invented path fails on one OS only.
        $override = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_operator_root';
        $this->withEnv('PULSAR_BASE_PATH', $override);
        $this->withEnv('APP_ENV', 'local');

        $kernel = new Kernel(configManager: new ConfigManager(configPath: $configPath));
        $kernel->boot();

        self::assertSame($override, getenv('PULSAR_BASE_PATH'));
    }

    #[Test]
    public function it_rejects_cache_without_app_config(): void
    {
        $emptyRepo = new ConfigRepository();

        $configManager = new ConfigManager(
            configPath: $this->basePath . DIRECTORY_SEPARATOR . 'config',
        );

        $result = $configManager->loadFromCache($emptyRepo);

        self::assertFalse($result);
    }

    #[Test]
    public function it_falls_through_to_file_loading_when_no_cache(): void
    {
        // Write minimal required config files for ConfigManager::load()
        $configPath = $this->basePath . DIRECTORY_SEPARATOR . 'config';
        $this->writeMinimalConfigs($configPath);

        $configManager = new ConfigManager(configPath: $configPath);
        $kernel = new Kernel(configManager: $configManager);

        // No FrameworkCache in container → should fall through to normal load
        $kernel->boot();

        self::assertTrue($kernel->booted);
        self::assertTrue($configManager->repository()->has(AppConfig::class));
    }

    #[Test]
    public function it_loads_environment_on_cache_load(): void
    {
        $cachedRepo = new ConfigRepository();
        $cachedRepo->set(new AppConfig(
            name: 'EnvTest',
            mode: EnvironmentMode::Local,
            debug: true,
            timezone: 'UTC',
            locale: 'en',
        ));

        $configManager = new ConfigManager(
            configPath: $this->basePath . DIRECTORY_SEPARATOR . 'config',
        );

        $configManager->loadFromCache($cachedRepo);

        // Environment should be loaded even from cache path
        $env = $configManager->environment();
        self::assertInstanceOf(Environment::class, $env);
    }

    #[Test]
    public function productionBootHitsTheCacheAfterWarm(): void
    {
        // FrameworkCache is bound by SecurityWiring, and the cache-load gate
        // reads it: bind it after the gate and every boot is a cold boot, no
        // matter what `optimize` wrote. FrameworkCache::load() tested in
        // isolation cannot see that ordering, so this asserts against a real
        // boot instead.
        $configPath = $this->basePath . DIRECTORY_SEPARATOR . 'config';
        $this->writeMinimalConfigs($configPath);

        $key = bin2hex(random_bytes(32));
        $this->withEnv('PULSAR_MASTER_KEY', $key);
        $this->withEnv('CACHE_ENCRYPT', null);
        // Keep the boot out of production mode so it does not demand build
        // artifacts — this test exercises the cache-hit path, not `pulsar build`.
        $this->withEnv('APP_ENV', 'local');

        // Warm the cache from a COMPLETE repository (as `optimize` does), but
        // override the app name so a cache HIT is provable: the booted config
        // must carry the cached name, which only the cache (not the files) could
        // supply.
        $sourceManager = new ConfigManager(configPath: $configPath);
        $sourceManager->load();
        $fullRepo = $sourceManager->repository();
        $fullRepo->set(new AppConfig(
            name: 'WarmedFromCache',
            mode: EnvironmentMode::Local,
            debug: true,
            timezone: 'UTC',
            locale: 'en',
        ));

        $warmCache = new FrameworkCache(
            $this->basePath,
            MasterKey::fromHex($key),
            new HmacService(),
        );
        $warmCache->warm($fullRepo, [], [], 'local', false);

        // Boot the production path: a fresh Kernel with only a ConfigManager —
        // it must pre-bind FrameworkCache from the environment and hit the cache.
        $configManager = new ConfigManager(configPath: $configPath);
        $kernel = new Kernel(configManager: $configManager);
        $kernel->boot();

        $profile = $kernel->bootProfile();
        self::assertNotNull($profile);
        self::assertTrue($profile->cacheHit, 'the cache must be loaded on a cold production boot');
        self::assertSame(
            'WarmedFromCache',
            $configManager->repository()->get(AppConfig::class)->name,
            'the cached config, not the on-disk file, must be the source',
        );
    }

    /**
     * `optimize --strict` locks the router to the cached table, and the boot
     * that follows runs every wiring — which registers the same routes again.
     * With the lock refusing all of them, `I18nWiring` (third in the boot order)
     * threw `RoutingException::routerLocked()` and no deployment that had run
     * `optimize --strict` could start.
     *
     * The whole path is exercised here rather than any part of it: a real
     * FrameworkCache warmed strict from a real cold boot's route table, then a
     * second Kernel booting from it. `productionBootHitsTheCacheAfterWarm`
     * warms non-strict, so it never locked the router and never saw this.
     */
    #[Test]
    public function aStrictWarmedCacheStillBoots(): void
    {
        $configPath = $this->basePath . DIRECTORY_SEPARATOR . 'config';
        $this->writeMinimalConfigs($configPath);

        // i18n activates I18nWiring, which registers the translation-bundle
        // route unconditionally — the registration the lock used to refuse.
        file_put_contents(
            $configPath . DIRECTORY_SEPARATOR . 'i18n.php',
            "<?php\nreturn ['default_locale' => 'en', 'supported_locales' => ['en'], 'fallback_locales' => ['en'], 'regulated' => false];\n",
        );

        $key = bin2hex(random_bytes(32));
        $this->withEnv('PULSAR_MASTER_KEY', $key);
        $this->withEnv('CACHE_ENCRYPT', null);
        $this->withEnv('APP_ENV', 'local');

        // What `optimize` does: cold-boot, then cache the router's own table.
        $sourceRouter = new Router();
        $sourceManager = new ConfigManager(configPath: $configPath);
        new Kernel(router: $sourceRouter, configManager: $sourceManager)->boot();

        self::assertNotNull(
            $sourceRouter->getByName('api.i18n.locale'),
            'the fixture must contain a wiring-registered route, or it proves nothing',
        );

        new FrameworkCache($this->basePath, MasterKey::fromHex($key), new HmacService())->warm(
            $sourceManager->repository(),
            $sourceRouter->routes,
            [],
            'local',
            true,
            CachedRouteReconstructor::forCache($sourceRouter->explicitBindings),
        );

        $router = new Router();
        $kernel = new Kernel(router: $router, configManager: new ConfigManager(configPath: $configPath));

        $kernel->boot();

        self::assertTrue($kernel->booted);
        self::assertTrue($router->locked, 'a strict cache must still lock the router');

        $profile = $kernel->bootProfile();
        self::assertNotNull($profile);
        self::assertTrue($profile->cacheHit, 'the strict cache must actually have been loaded');

        // The replayed registrations must leave the cached table as the cache
        // stated it, not append a second copy of every route.
        self::assertCount($sourceRouter->count(), $router->routes());
        self::assertNotNull($router->getByName('api.i18n.locale'));
    }

    #[Test]
    public function bootDegradesToNoCacheWithoutAMasterKey(): void
    {
        $configPath = $this->basePath . DIRECTORY_SEPARATOR . 'config';
        $this->writeMinimalConfigs($configPath);

        $this->withEnv('PULSAR_MASTER_KEY', null);

        $configManager = new ConfigManager(configPath: $configPath);
        $kernel = new Kernel(configManager: $configManager);

        // No key → no pre-bound cache, but boot must still succeed (caching is an
        // optimization, never a boot requirement).
        $kernel->boot();

        self::assertTrue($kernel->booted);
        $profile = $kernel->bootProfile();
        self::assertNotNull($profile);
        self::assertFalse($profile->cacheHit);
    }

    private function writeMinimalConfigs(string $configPath): void
    {
        file_put_contents(
            $configPath . DIRECTORY_SEPARATOR . 'app.php',
            "<?php\nreturn ['name' => 'TestApp', 'debug' => true, 'url' => 'http://localhost', 'timezone' => 'UTC'];\n",
        );
        file_put_contents(
            $configPath . DIRECTORY_SEPARATOR . 'observability.php',
            "<?php\nreturn [];\n",
        );
        file_put_contents(
            $configPath . DIRECTORY_SEPARATOR . 'security.php',
            "<?php\nreturn [];\n",
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
