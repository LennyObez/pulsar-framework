<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cache;

use function bin2hex;
use function file_put_contents;
use function is_dir;
use function mkdir;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\ConfigCache;
use Pulsar\Cache\ContainerCache;
use Pulsar\Cache\FrameworkCache;
use Pulsar\Cache\RouteCache;
use Pulsar\Security\Crypto\MasterKey;

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

        // Write a minimal app.php config so invalidation key is deterministic
        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php',
            "<?php\nreturn ['name' => 'test', 'debug' => true];\n",
        );

        $this->masterKey = MasterKey::fromHex(sodium_bin2hex(random_bytes(32)));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->basePath);
    }

    #[Test]
    public function it_reports_not_warm_when_no_cache_exists(): void
    {
        $cache = new FrameworkCache($this->basePath, $this->masterKey);

        self::assertFalse($cache->isWarm());
    }

    #[Test]
    public function it_returns_correct_cache_path(): void
    {
        $cache = new FrameworkCache($this->basePath, $this->masterKey);

        $expected = $this->basePath . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'framework';
        self::assertSame($expected, $cache->cachePath());
    }

    #[Test]
    public function it_computes_deterministic_invalidation_key(): void
    {
        $cache = new FrameworkCache($this->basePath, $this->masterKey);
        $configPath = $this->basePath . DIRECTORY_SEPARATOR . 'config';

        $key1 = $cache->computeInvalidationKey($configPath);
        $key2 = $cache->computeInvalidationKey($configPath);

        self::assertSame($key1, $key2);
        self::assertSame(64, strlen($key1));
    }

    #[Test]
    public function it_produces_different_invalidation_key_when_config_changes(): void
    {
        $cache = new FrameworkCache($this->basePath, $this->masterKey);
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
        $cache = new FrameworkCache($this->basePath, $this->masterKey);
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
        $cache = new FrameworkCache($this->basePath, $this->masterKey);
        $configPath = $this->basePath . DIRECTORY_SEPARATOR . 'config';

        $result = $cache->load($configPath);

        self::assertNull($result);
    }

    #[Test]
    public function it_includes_composer_lock_in_invalidation_key(): void
    {
        $cache = new FrameworkCache($this->basePath, $this->masterKey);
        $configPath = $this->basePath . DIRECTORY_SEPARATOR . 'config';

        $key1 = $cache->computeInvalidationKey($configPath);

        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'composer.lock',
            '{"packages":[]}',
        );

        $key2 = $cache->computeInvalidationKey($configPath);

        self::assertNotSame($key1, $key2);
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
