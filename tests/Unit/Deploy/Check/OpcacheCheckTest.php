<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Deploy\Check;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Deploy\Check\OpcacheCheck;
use Pulsar\Deploy\CheckSeverity;
use Pulsar\Tests\Unit\Deploy\Runtime\StubFilesystemReader;
use Pulsar\Tests\Unit\Deploy\Runtime\StubPhpRuntime;

#[CoversClass(OpcacheCheck::class)]
final class OpcacheCheckTest extends TestCase
{
    // --- Core OPcache checks ---

    #[Test]
    public function it_returns_name(): void
    {
        $check = new OpcacheCheck(new StubPhpRuntime());

        self::assertSame('opcache', $check->getName());
    }

    #[Test]
    public function it_returns_description(): void
    {
        $check = new OpcacheCheck(new StubPhpRuntime());

        self::assertStringContainsString('OPcache', $check->getDescription());
    }

    #[Test]
    public function it_errors_when_not_loaded_in_production(): void
    {
        $check = new OpcacheCheck(new StubPhpRuntime());

        $result = $check->check('production');

        self::assertSame(CheckSeverity::Error, $result->severity);
        self::assertStringContainsString('not loaded', $result->message);
    }

    #[Test]
    public function it_warns_when_not_loaded_in_staging(): void
    {
        $check = new OpcacheCheck(new StubPhpRuntime());

        $result = $check->check('staging');

        self::assertSame(CheckSeverity::Warning, $result->severity);
    }

    #[Test]
    public function it_passes_when_not_loaded_in_local(): void
    {
        $check = new OpcacheCheck(new StubPhpRuntime());

        $result = $check->check('local');

        self::assertSame(CheckSeverity::Pass, $result->severity);
    }

    #[Test]
    public function it_errors_when_disabled_in_production(): void
    {
        $check = new OpcacheCheck(new StubPhpRuntime(
            iniValues: ['opcache.enable' => '0'],
            loadedExtensions: ['Zend OPcache'],
        ));

        $result = $check->check('production');

        self::assertSame(CheckSeverity::Error, $result->severity);
    }

    #[Test]
    public function it_warns_when_revalidation_too_low(): void
    {
        $check = new OpcacheCheck($this->fullyConfiguredRuntime(
            revalidateFreq: '30',
        ));

        $result = $check->check('production');

        self::assertSame(CheckSeverity::Warning, $result->severity);
        self::assertStringContainsString('revalidate_freq', $result->message);
    }

    // --- Preload checks ---

    #[Test]
    public function it_warns_when_preload_not_configured(): void
    {
        $check = new OpcacheCheck($this->fullyConfiguredRuntime(
            preload: '',
        ));

        $result = $check->check('production');

        self::assertSame(CheckSeverity::Warning, $result->severity);
        self::assertStringContainsString('not configured', $result->message);
    }

    #[Test]
    public function it_warns_on_relative_preload_path(): void
    {
        $check = new OpcacheCheck($this->fullyConfiguredRuntime(
            preload: 'preload.generated.php',
        ));

        $result = $check->check('production');

        self::assertSame(CheckSeverity::Warning, $result->severity);
        self::assertStringContainsString('relative', $result->message);
    }

    #[Test]
    public function it_warns_on_unsafe_tmp_directory(): void
    {
        $check = new OpcacheCheck($this->fullyConfiguredRuntime(
            preload: '/tmp/preload.php',
        ));

        $result = $check->check('production');

        self::assertSame(CheckSeverity::Warning, $result->severity);
        self::assertStringContainsString('writable directory', $result->message);
        self::assertStringContainsString('RCE', $result->message);
    }

    #[Test]
    public function it_warns_on_unsafe_var_cache_directory(): void
    {
        $check = new OpcacheCheck($this->fullyConfiguredRuntime(
            preload: '/var/cache/myapp/preload.php',
        ));

        $result = $check->check('production');

        self::assertSame(CheckSeverity::Warning, $result->severity);
        self::assertStringContainsString('writable directory', $result->message);
    }

    #[Test]
    public function it_warns_on_traversal_in_preload_path(): void
    {
        $check = new OpcacheCheck($this->fullyConfiguredRuntime(
            preload: '/opt/app/../../../tmp/preload.php',
        ));

        $result = $check->check('production');

        self::assertSame(CheckSeverity::Warning, $result->severity);
        self::assertStringContainsString('writable directory', $result->message);
    }

    #[Test]
    public function it_warns_on_windows_temp_directory(): void
    {
        $check = new OpcacheCheck($this->fullyConfiguredRuntime(
            preload: 'C:\\Windows\\Temp\\preload.php',
        ));

        $result = $check->check('production');

        self::assertSame(CheckSeverity::Warning, $result->severity);
        self::assertStringContainsString('writable directory', $result->message);
    }

    #[Test]
    public function it_warns_when_preload_file_not_readable(): void
    {
        $filesystem = new StubFilesystemReader([
            '/opt/app/preload.generated.php' => false,
        ]);

        $check = new OpcacheCheck(
            $this->fullyConfiguredRuntime(preload: '/opt/app/preload.generated.php'),
            $filesystem,
        );

        $result = $check->check('production');

        self::assertSame(CheckSeverity::Warning, $result->severity);
        self::assertStringContainsString('not readable', $result->message);
    }

    #[Test]
    public function it_warns_when_preload_user_not_set(): void
    {
        $filesystem = new StubFilesystemReader([
            '/opt/app/preload.generated.php' => true,
        ]);

        $check = new OpcacheCheck(
            $this->fullyConfiguredRuntime(
                preload: '/opt/app/preload.generated.php',
                preloadUser: '',
            ),
            $filesystem,
        );

        $result = $check->check('production');

        self::assertSame(CheckSeverity::Warning, $result->severity);
        self::assertStringContainsString('preload_user', $result->message);
    }

    #[Test]
    public function it_passes_with_fully_configured_preload(): void
    {
        $filesystem = new StubFilesystemReader([
            '/opt/app/preload.generated.php' => true,
        ]);

        $check = new OpcacheCheck(
            $this->fullyConfiguredRuntime(
                preload: '/opt/app/preload.generated.php',
                preloadUser: 'www-data',
            ),
            $filesystem,
        );

        $result = $check->check('production');

        self::assertSame(CheckSeverity::Pass, $result->severity);
    }

    #[Test]
    public function it_passes_in_local_without_preload(): void
    {
        $check = new OpcacheCheck(new StubPhpRuntime(
            iniValues: ['opcache.enable' => '1'],
            loadedExtensions: ['Zend OPcache'],
        ));

        $result = $check->check('local');

        self::assertSame(CheckSeverity::Pass, $result->severity);
    }

    #[Test]
    public function it_accepts_windows_absolute_preload_path(): void
    {
        $filesystem = new StubFilesystemReader([
            'D:\\apps\\myapp\\preload.generated.php' => true,
        ]);

        $check = new OpcacheCheck(
            $this->fullyConfiguredRuntime(
                preload: 'D:\\apps\\myapp\\preload.generated.php',
                preloadUser: 'www-data',
            ),
            $filesystem,
        );

        $result = $check->check('production');

        self::assertSame(CheckSeverity::Pass, $result->severity);
    }

    private function fullyConfiguredRuntime(
        string $revalidateFreq = '60',
        string $preload = '/opt/app/preload.generated.php',
        string $preloadUser = 'www-data',
    ): StubPhpRuntime {
        return new StubPhpRuntime(
            iniValues: [
                'opcache.enable' => '1',
                'opcache.revalidate_freq' => $revalidateFreq,
                'opcache.preload' => $preload,
                'opcache.preload_user' => $preloadUser,
            ],
            loadedExtensions: ['Zend OPcache'],
        );
    }
}
