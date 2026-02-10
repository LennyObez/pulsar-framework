<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Deploy\Check;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\FrameworkCache;
use Pulsar\Config\ConfigManager;
use Pulsar\Deploy\Check\FilesystemScanCheck;
use Pulsar\Deploy\CheckSeverity;
use Pulsar\Security\Crypto\HmacService;
use Pulsar\Security\Crypto\MasterKey;

use function bin2hex;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function random_bytes;
use function scandir;
use function sodium_bin2hex;

#[CoversClass(FilesystemScanCheck::class)]
final class FilesystemScanCheckTest extends TestCase
{
    private string $basePath;
    private MasterKey $masterKey;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_fs_scan_test_' . bin2hex(random_bytes(8));
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'config', 0o750, true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'composer', 0o750, true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'src', 0o750, true);
        $this->writeConfigStubs($this->basePath . DIRECTORY_SEPARATOR . 'config');
        $this->masterKey = MasterKey::fromHex(sodium_bin2hex(random_bytes(32)));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->basePath);
    }

    #[Test]
    public function passesInLocalEnvironment(): void
    {
        $cache = new FrameworkCache($this->basePath, $this->masterKey, new HmacService());
        $check = new FilesystemScanCheck($cache);

        $result = $check->check('local');

        self::assertSame(CheckSeverity::Pass, $result->severity);
    }

    #[Test]
    public function passesWhenCacheIsWarm(): void
    {
        $cache = new FrameworkCache($this->basePath, $this->masterKey, new HmacService());
        $configManager = new ConfigManager($this->basePath . DIRECTORY_SEPARATOR . 'config');
        $configManager->load();
        $cache->warm($configManager->repository(), [], [], 'production', false);

        $check = new FilesystemScanCheck($cache);

        $result = $check->check('production');

        self::assertSame(CheckSeverity::Pass, $result->severity);
    }

    #[Test]
    public function errorWhenColdInProduction(): void
    {
        $cache = new FrameworkCache($this->basePath, $this->masterKey, new HmacService());
        $check = new FilesystemScanCheck($cache);

        $result = $check->check('production');

        self::assertSame(CheckSeverity::Error, $result->severity);
        self::assertNotEmpty($result->recommendations);
    }

    #[Test]
    public function warningWhenColdInStaging(): void
    {
        $cache = new FrameworkCache($this->basePath, $this->masterKey, new HmacService());
        $check = new FilesystemScanCheck($cache);

        $result = $check->check('staging');

        self::assertSame(CheckSeverity::Warning, $result->severity);
    }

    #[Test]
    public function nameAndDescription(): void
    {
        $cache = new FrameworkCache($this->basePath, $this->masterKey, new HmacService());
        $check = new FilesystemScanCheck($cache);

        self::assertSame('filesystem-scan', $check->getName());
        self::assertNotEmpty($check->getDescription());
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
            "<?php\nreturn ['session' => ['cookie_name' => 'TEST', 'lifetime' => 3600, 'cookie_httponly' => true, 'cookie_secure' => false, 'cookie_samesite' => 'Lax', 'regenerate_on_privilege_change' => true], 'csrf' => ['enabled' => false, 'token_length' => 32, 'header_name' => 'X-CSRF-Token', 'form_field_name' => '_csrf'], 'headers' => [], 'rate_limiting' => ['enabled' => false, 'default_limit' => 60, 'default_window' => 60]];\n",
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
