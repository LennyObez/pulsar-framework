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
use Pulsar\Core\Kernel;

use function bin2hex;
use function is_dir;
use function mkdir;
use function random_bytes;
use function scandir;

#[CoversClass(Kernel::class)]
#[CoversClass(ConfigManager::class)]
final class CachedBootTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_cached_boot_test_' . bin2hex(random_bytes(8));
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'config', 0o750, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->basePath);
    }

    #[Test]
    public function it_uses_cached_config_when_framework_cache_is_available(): void
    {
        // Build a cached ConfigRepository
        $cachedRepo = new ConfigRepository();
        $cachedRepo->set(new AppConfig(
            name: 'CachedApp',
            mode: EnvironmentMode::Local,
            debug: true,
            timezone: 'UTC',
            locale: 'en',
        ));

        // Create a ConfigManager with a configPath
        $configManager = new ConfigManager(
            configPath: $this->basePath . DIRECTORY_SEPARATOR . 'config',
        );

        // Verify loadFromCache succeeds
        $result = $configManager->loadFromCache($cachedRepo);

        self::assertTrue($result);
        self::assertSame($cachedRepo, $configManager->repository());
        self::assertSame('CachedApp', $configManager->repository()->get(AppConfig::class)->name);
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
