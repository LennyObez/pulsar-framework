<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cache;

use function bin2hex;
use function is_dir;
use function mkdir;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\CacheIntegrity;
use Pulsar\Cache\ConfigCache;
use Pulsar\Config\AppConfig;
use Pulsar\Config\ConfigRepository;
use Pulsar\Config\EnvironmentMode;
use Pulsar\Security\Crypto\Encryptor;
use Pulsar\Security\Crypto\MasterKey;

use function random_bytes;
use function scandir;
use function sodium_bin2hex;

#[CoversClass(ConfigCache::class)]
final class ConfigCacheTest extends TestCase
{
    private string $tempDir;
    private CacheIntegrity $integrity;
    private ConfigCache $configCache;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_config_cache_test_' . bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o750, true);

        $hmacKey = random_bytes(32);
        $this->integrity = new CacheIntegrity($hmacKey);
        $this->configCache = new ConfigCache($this->integrity);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    #[Test]
    public function it_writes_and_loads_config_repository(): void
    {
        $repository = new ConfigRepository();
        $appConfig = new AppConfig(
            name: 'TestApp',
            mode: EnvironmentMode::Local,
            debug: true,
            timezone: 'UTC',
            locale: 'en',
        );
        $repository->set($appConfig);

        $this->configCache->write($this->tempDir, $repository, false);

        self::assertFileExists($this->tempDir . DIRECTORY_SEPARATOR . ConfigCache::FILENAME);

        $loaded = $this->configCache->load($this->tempDir, [
            ConfigRepository::class,
            AppConfig::class,
            EnvironmentMode::class,
        ]);

        self::assertInstanceOf(ConfigRepository::class, $loaded);
        self::assertTrue($loaded->has(AppConfig::class));

        /** @var AppConfig $loadedConfig */
        $loadedConfig = $loaded->get(AppConfig::class);
        self::assertSame('TestApp', $loadedConfig->name);
        self::assertSame(EnvironmentMode::Local, $loadedConfig->mode);
        self::assertTrue($loadedConfig->debug);
    }

    #[Test]
    public function it_writes_and_loads_encrypted_config(): void
    {
        $masterKey = MasterKey::fromHex(sodium_bin2hex(random_bytes(32)));
        $encryptor = Encryptor::fromDerivedKey($masterKey, 8, 'fw_c_enc');
        $integrity = new CacheIntegrity(random_bytes(32), $encryptor);
        $cache = new ConfigCache($integrity);

        $repository = new ConfigRepository();
        $appConfig = new AppConfig(
            name: 'EncryptedApp',
            mode: EnvironmentMode::Production,
            debug: false,
            timezone: 'UTC',
            locale: 'en',
        );
        $repository->set($appConfig);

        $cache->write($this->tempDir, $repository, true);

        $loaded = $cache->load($this->tempDir, [
            ConfigRepository::class,
            AppConfig::class,
            EnvironmentMode::class,
        ]);

        self::assertInstanceOf(ConfigRepository::class, $loaded);

        /** @var AppConfig $loadedConfig */
        $loadedConfig = $loaded->get(AppConfig::class);
        self::assertSame('EncryptedApp', $loadedConfig->name);
        self::assertFalse($loadedConfig->debug);
    }

    #[Test]
    public function it_returns_null_for_missing_cache_file(): void
    {
        $loaded = $this->configCache->load($this->tempDir, []);

        self::assertNull($loaded);
    }

    #[Test]
    public function it_returns_null_when_deserialized_type_is_wrong(): void
    {
        // Write an envelope containing a non-ConfigRepository object
        $path = $this->tempDir . DIRECTORY_SEPARATOR . ConfigCache::FILENAME;
        $this->integrity->writeEnvelope($path, serialize(['not' => 'a repository']), false);

        $loaded = $this->configCache->load($this->tempDir, []);

        self::assertNull($loaded);
    }

    #[Test]
    public function it_preserves_multiple_config_dtos(): void
    {
        $repository = new ConfigRepository();

        $appConfig = new AppConfig(
            name: 'MultiConfig',
            mode: EnvironmentMode::Staging,
            debug: false,
            timezone: 'America/New_York',
            locale: 'en',
        );
        $repository->set($appConfig);

        $this->configCache->write($this->tempDir, $repository, false);

        $loaded = $this->configCache->load($this->tempDir, [
            ConfigRepository::class,
            AppConfig::class,
            EnvironmentMode::class,
        ]);

        self::assertInstanceOf(ConfigRepository::class, $loaded);
        self::assertTrue($loaded->has(AppConfig::class));

        /** @var AppConfig $loadedApp */
        $loadedApp = $loaded->get(AppConfig::class);
        self::assertSame('America/New_York', $loadedApp->timezone);
    }

    #[Test]
    public function it_has_correct_filename_constant(): void
    {
        self::assertSame('config.cache.bin', ConfigCache::FILENAME);
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
