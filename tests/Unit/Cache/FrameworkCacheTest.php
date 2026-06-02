<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cache;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\ConfigCache;
use Pulsar\Cache\ContainerCache;
use Pulsar\Cache\FrameworkCache;
use Pulsar\Cache\RouteCache;
use Pulsar\Config\ConfigManager;
use Pulsar\Routing\Route;
use Pulsar\Security\Crypto\HmacService;
use Pulsar\Security\Crypto\MasterKey;

use function bin2hex;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function random_bytes;
use function scandir;
use function sodium_bin2hex;
use function strlen;

#[CoversClass(FrameworkCache::class)]
final class FrameworkCacheTest extends TestCase
{
    private string $basePath;
    private MasterKey $masterKey;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_fw_cache_test_' . bin2hex(random_bytes(8));
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'config', 0o750, true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'composer', 0o750, true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'src', 0o750, true);

        // Write minimal config stubs (app, observability, security are mandatory)
        $this->writeConfigStubs($this->basePath . DIRECTORY_SEPARATOR . 'config');

        $this->masterKey = MasterKey::fromHex(sodium_bin2hex(random_bytes(32)));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->basePath);
    }

    #[Test]
    public function it_reports_not_warm_when_no_cache_exists(): void
    {
        $cache = new FrameworkCache($this->basePath, $this->masterKey, new HmacService());

        self::assertFalse($cache->isWarm());
    }

    #[Test]
    public function it_returns_correct_cache_path(): void
    {
        $cache = new FrameworkCache($this->basePath, $this->masterKey, new HmacService());

        $expected = $this->basePath . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'framework';
        self::assertSame($expected, $cache->cachePath());
    }

    #[Test]
    public function it_computes_deterministic_invalidation_key(): void
    {
        $cache = new FrameworkCache($this->basePath, $this->masterKey, new HmacService());
        $configPath = $this->basePath . DIRECTORY_SEPARATOR . 'config';

        $key1 = $cache->computeInvalidationKey($configPath);
        $key2 = $cache->computeInvalidationKey($configPath);

        self::assertSame($key1, $key2);
        self::assertSame(64, strlen($key1));
    }

    #[Test]
    public function it_produces_different_invalidation_key_when_config_changes(): void
    {
        $cache = new FrameworkCache($this->basePath, $this->masterKey, new HmacService());
        $configPath = $this->basePath . DIRECTORY_SEPARATOR . 'config';

        $key1 = $cache->computeInvalidationKey($configPath);

        // Modify config
        file_put_contents(
            $configPath . DIRECTORY_SEPARATOR . 'app.php',
            "<?php\nreturn ['name' => 'changed', 'debug' => false];\n",
        );

        $key2 = $cache->computeInvalidationKey($configPath);

        self::assertNotSame($key1, $key2);
    }

    #[Test]
    public function it_clears_cache_files(): void
    {
        $cache = new FrameworkCache($this->basePath, $this->masterKey, new HmacService());
        $cachePath = $cache->cachePath();
        mkdir($cachePath, 0o750, true);

        // Create dummy cache files
        file_put_contents($cachePath . DIRECTORY_SEPARATOR . ConfigCache::FILENAME, 'data');
        file_put_contents($cachePath . DIRECTORY_SEPARATOR . RouteCache::FILENAME, 'data');
        file_put_contents($cachePath . DIRECTORY_SEPARATOR . ContainerCache::FILENAME, 'data');
        file_put_contents($cachePath . DIRECTORY_SEPARATOR . 'manifest.json', '{}');
        file_put_contents($cachePath . DIRECTORY_SEPARATOR . 'allowed_classes.json', '[]');

        $cache->clear();

        self::assertFileDoesNotExist($cachePath . DIRECTORY_SEPARATOR . ConfigCache::FILENAME);
        self::assertFileDoesNotExist($cachePath . DIRECTORY_SEPARATOR . RouteCache::FILENAME);
        self::assertFileDoesNotExist($cachePath . DIRECTORY_SEPARATOR . ContainerCache::FILENAME);
        self::assertFileDoesNotExist($cachePath . DIRECTORY_SEPARATOR . 'manifest.json');
        self::assertFileDoesNotExist($cachePath . DIRECTORY_SEPARATOR . 'allowed_classes.json');
    }

    #[Test]
    public function it_returns_null_when_loading_from_nonexistent_cache_directory(): void
    {
        $cache = new FrameworkCache($this->basePath, $this->masterKey, new HmacService());
        $configPath = $this->basePath . DIRECTORY_SEPARATOR . 'config';

        $result = $cache->load($configPath);

        self::assertNull($result);
    }

    #[Test]
    public function it_warms_and_becomes_warm(): void
    {
        $cache = new FrameworkCache($this->basePath, $this->masterKey, new HmacService());
        $configManager = new ConfigManager($this->basePath . DIRECTORY_SEPARATOR . 'config');
        $configManager->load();
        $repository = $configManager->repository();

        $routes = [
            Route::get('/test', self::class, 'test.index'),
        ];

        $result = $cache->warm($repository, $routes, [], 'testing', false);

        self::assertTrue($result['configCached']);
        self::assertSame(1, $result['routesCached']);
        self::assertSame(0, $result['routesSkipped']);
        self::assertTrue($cache->isWarm());
    }

    #[Test]
    public function it_warms_with_container_hints(): void
    {
        $cache = new FrameworkCache($this->basePath, $this->masterKey, new HmacService());
        $configManager = new ConfigManager($this->basePath . DIRECTORY_SEPARATOR . 'config');
        $configManager->load();
        $repository = $configManager->repository();

        /** @var array<class-string, list<array{name: string, type: class-string}>> $hints */
        $hints = [];

        $result = $cache->warm($repository, [], $hints, 'production', true);

        self::assertTrue($result['configCached']);
        self::assertTrue($result['containerCached']);
        self::assertTrue($cache->isWarm());
    }

    #[Test]
    public function it_clears_after_warming(): void
    {
        $cache = new FrameworkCache($this->basePath, $this->masterKey, new HmacService());
        $configManager = new ConfigManager($this->basePath . DIRECTORY_SEPARATOR . 'config');
        $configManager->load();
        $repository = $configManager->repository();

        $cache->warm($repository, [], [], 'testing', false);
        self::assertTrue($cache->isWarm());

        $cache->clear();
        self::assertFalse($cache->isWarm());
    }

    #[Test]
    public function it_includes_composer_lock_in_invalidation_key(): void
    {
        $cache = new FrameworkCache($this->basePath, $this->masterKey, new HmacService());
        $configPath = $this->basePath . DIRECTORY_SEPARATOR . 'config';

        $key1 = $cache->computeInvalidationKey($configPath);

        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'composer.lock',
            '{"packages":[]}',
        );

        $key2 = $cache->computeInvalidationKey($configPath);

        self::assertNotSame($key1, $key2);
    }

    #[Test]
    public function loadReturnsNullWhenManifestIsInvalid(): void
    {
        $cache = new FrameworkCache($this->basePath, $this->masterKey, new HmacService());
        $cachePath = $cache->cachePath();
        mkdir($cachePath, 0o750, true);

        file_put_contents($cachePath . DIRECTORY_SEPARATOR . 'manifest.json', '{"invalid": true}');

        $result = $cache->load($this->basePath . DIRECTORY_SEPARATOR . 'config');

        self::assertNull($result);
    }

    #[Test]
    public function warmAndLoadRoundTrip(): void
    {
        $cache = new FrameworkCache($this->basePath, $this->masterKey, new HmacService());
        $configManager = new ConfigManager($this->basePath . DIRECTORY_SEPARATOR . 'config');
        $configManager->load();
        $repository = $configManager->repository();

        $routes = [
            Route::get('/test', self::class, 'test.index'),
            Route::get('/users', self::class, 'users.index'),
        ];

        $cache->warm($repository, $routes, [], 'testing', false);

        self::assertTrue($cache->isWarm());

        $loaded = $cache->load($this->basePath . DIRECTORY_SEPARATOR . 'config');

        self::assertNotNull($loaded);
        self::assertArrayHasKey('manifest', $loaded);
        self::assertArrayHasKey('config', $loaded);
        self::assertArrayHasKey('routes', $loaded);
        self::assertArrayHasKey('containerHints', $loaded);
    }

    #[Test]
    public function loadReturnsNullWhenInvalidationKeyDoesNotMatch(): void
    {
        $cache = new FrameworkCache($this->basePath, $this->masterKey, new HmacService());
        $configManager = new ConfigManager($this->basePath . DIRECTORY_SEPARATOR . 'config');
        $configManager->load();
        $repository = $configManager->repository();

        $cache->warm($repository, [], [], 'testing', false);

        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php',
            "<?php\nreturn ['name' => 'CHANGED', 'env' => 'production', 'debug' => false, 'timezone' => 'UTC', 'locale' => 'en'];\n",
        );

        $loaded = $cache->load($this->basePath . DIRECTORY_SEPARATOR . 'config');

        self::assertNull($loaded);
    }

    #[Test]
    public function invalidationKeyChangesWithEnvFile(): void
    {
        $cache = new FrameworkCache($this->basePath, $this->masterKey, new HmacService());
        $configPath = $this->basePath . DIRECTORY_SEPARATOR . 'config';

        $key1 = $cache->computeInvalidationKey($configPath);

        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . '.env',
            "APP_KEY=test-key\nDB_HOST=localhost\n",
        );

        $key2 = $cache->computeInvalidationKey($configPath);

        self::assertNotSame($key1, $key2);
    }

    #[Test]
    public function warmWithStrictMode(): void
    {
        $cache = new FrameworkCache($this->basePath, $this->masterKey, new HmacService());
        $configManager = new ConfigManager($this->basePath . DIRECTORY_SEPARATOR . 'config');
        $configManager->load();
        $repository = $configManager->repository();

        $routes = [
            Route::get('/api', self::class, 'api.index'),
        ];

        $result = $cache->warm($repository, $routes, [], 'production', true);

        self::assertTrue($result['configCached']);
        self::assertSame(1, $result['routesCached']);
        self::assertTrue($result['containerCached']);
        self::assertTrue($cache->isWarm());
    }

    #[Test]
    public function loadRejectsTamperedConfigCacheFileWithIntactManifest(): void
    {
        $cache = new FrameworkCache($this->basePath, $this->masterKey, new HmacService());
        $configManager = new ConfigManager($this->basePath . DIRECTORY_SEPARATOR . 'config');
        $configManager->load();
        $repository = $configManager->repository();

        $cache->warm($repository, [], [], 'testing', false);
        self::assertTrue($cache->isWarm());

        // Replace the config cache binary while leaving the manifest (and its
        // own HMAC) untouched. The manifest HMAC still validates because it
        // only signs the recorded per-file signatures, not the files. load()
        // must still reject because the per-file signature no longer matches.
        $configFile = $cache->cachePath() . DIRECTORY_SEPARATOR . ConfigCache::FILENAME;
        file_put_contents($configFile, 'tampered-cache-payload');

        $loaded = $cache->load($this->basePath . DIRECTORY_SEPARATOR . 'config');

        self::assertNull($loaded);
    }

    #[Test]
    public function loadRejectsTamperedRouteCacheFileWithIntactManifest(): void
    {
        $cache = new FrameworkCache($this->basePath, $this->masterKey, new HmacService());
        $configManager = new ConfigManager($this->basePath . DIRECTORY_SEPARATOR . 'config');
        $configManager->load();
        $repository = $configManager->repository();

        $cache->warm($repository, [Route::get('/x', self::class, 'x.index')], [], 'testing', false);
        self::assertTrue($cache->isWarm());

        $routeFile = $cache->cachePath() . DIRECTORY_SEPARATOR . RouteCache::FILENAME;
        file_put_contents($routeFile, 'tampered-route-payload');

        $loaded = $cache->load($this->basePath . DIRECTORY_SEPARATOR . 'config');

        self::assertNull($loaded);
    }

    #[Test]
    public function clearOnEmptyDirectoryDoesNotThrow(): void
    {
        $cache = new FrameworkCache($this->basePath, $this->masterKey, new HmacService());
        $cachePath = $cache->cachePath();
        mkdir($cachePath, 0o750, true);

        $cache->clear();

        self::assertFalse($cache->isWarm());
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
