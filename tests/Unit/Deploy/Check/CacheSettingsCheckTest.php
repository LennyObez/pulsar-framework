<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Deploy\Check;

use function bin2hex;

use const DIRECTORY_SEPARATOR;

use function is_dir;
use function is_file;
use function mkdir;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\CacheManifest;
use Pulsar\Cache\FrameworkCache;
use Pulsar\Deploy\Check\CacheSettingsCheck;
use Pulsar\Deploy\CheckSeverity;
use Pulsar\Security\Crypto\HmacService;
use Pulsar\Security\Crypto\MasterKey;

use function random_bytes;
use function rmdir;
use function sodium_bin2hex;
use function unlink;

#[CoversClass(CacheSettingsCheck::class)]
final class CacheSettingsCheckTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_cache_check_test_' . bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o750, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    #[Test]
    public function it_returns_name(): void
    {
        $check = new CacheSettingsCheck($this->buildColdCache());

        self::assertSame('cache-settings', $check->getName());
    }

    #[Test]
    public function it_returns_description(): void
    {
        $check = new CacheSettingsCheck($this->buildColdCache());

        self::assertSame('Validates the framework cache is warm for production performance', $check->getDescription());
    }

    #[Test]
    public function it_passes_when_cache_is_warm(): void
    {
        $check = new CacheSettingsCheck($this->buildWarmCache());

        $result = $check->check('production');

        self::assertSame(CheckSeverity::Pass, $result->severity);
        self::assertSame('Framework cache is warm', $result->message);
    }

    #[Test]
    public function it_passes_when_cache_warm_in_staging(): void
    {
        $check = new CacheSettingsCheck($this->buildWarmCache());

        $result = $check->check('staging');

        self::assertSame(CheckSeverity::Pass, $result->severity);
    }

    #[Test]
    public function it_warns_when_cache_cold_in_production(): void
    {
        $check = new CacheSettingsCheck($this->buildColdCache());

        $result = $check->check('production');

        self::assertSame(CheckSeverity::Warning, $result->severity);
        self::assertStringContainsString('Framework cache is not warm', $result->message);
        self::assertNotEmpty($result->recommendations);
    }

    #[Test]
    public function it_warns_when_cache_cold_in_staging(): void
    {
        $check = new CacheSettingsCheck($this->buildColdCache());

        $result = $check->check('staging');

        self::assertSame(CheckSeverity::Warning, $result->severity);
        self::assertStringContainsString('Framework cache is not warm', $result->message);
        self::assertNotEmpty($result->recommendations);
    }

    #[Test]
    public function it_passes_when_cache_cold_in_local(): void
    {
        $check = new CacheSettingsCheck($this->buildColdCache());

        $result = $check->check('local');

        self::assertSame(CheckSeverity::Pass, $result->severity);
        self::assertStringContainsString('not required in local', $result->message);
    }

    #[Test]
    public function production_recommendations_mention_optimize_command(): void
    {
        $check = new CacheSettingsCheck($this->buildColdCache());

        $result = $check->check('production');

        $joined = implode(' ', $result->recommendations);
        self::assertStringContainsString('php bin/pulsar optimize', $joined);
    }

    /**
     * Build a FrameworkCache that points to an empty temp dir (no manifest = cold).
     */
    private function buildColdCache(): FrameworkCache
    {
        $masterKey = MasterKey::fromHex(sodium_bin2hex(random_bytes(32)));

        return new FrameworkCache($this->tempDir, $masterKey, new HmacService());
    }

    /**
     * Build a FrameworkCache with a valid signed manifest (warm).
     */
    private function buildWarmCache(): FrameworkCache
    {
        $masterKey = MasterKey::fromHex(sodium_bin2hex(random_bytes(32)));
        $cache = new FrameworkCache($this->tempDir, $masterKey, new HmacService());

        // Create the cache directory structure and write a valid signed manifest
        $cachePath = $cache->cachePath();
        if (!is_dir($cachePath)) {
            mkdir($cachePath, 0o750, true);
        }

        $hmacKey = $masterKey->deriveSubKey(7, 'fw_cache');

        CacheManifest::write(
            hmac: new HmacService(),
            cachePath: $cachePath,
            hmacKey: $hmacKey,
            schemaVersion: 1,
            frameworkVersion: '1.0.0',
            appEnv: 'production',
            invalidationKey: 'test-key',
            allowedClassesHash: 'test-hash',
            caches: [],
            strict: false,
            encrypted: false,
        );

        return $cache;
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
            } elseif (is_file($path)) {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
