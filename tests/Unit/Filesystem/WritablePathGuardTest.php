<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Filesystem;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Filesystem\Exception\UnsafeWritablePathException;
use Pulsar\Filesystem\SafePath;
use Pulsar\Filesystem\WritablePathGuard;

use function bin2hex;
use function getenv;
use function mkdir;
use function putenv;
use function random_bytes;
use function rmdir;
use function sys_get_temp_dir;

use const DIRECTORY_SEPARATOR;

#[CoversClass(WritablePathGuard::class)]
#[CoversClass(SafePath::class)]
final class WritablePathGuardTest extends TestCase
{
    private string $base;
    private string|false $originalBasePath;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_wpg_' . bin2hex(random_bytes(8));
        mkdir($this->base . DIRECTORY_SEPARATOR . 'public', 0o750, true);
        // public_html must exist too, to prove the boundary check is not a raw
        // string prefix match (public_html would prefix-match "public").
        mkdir($this->base . DIRECTORY_SEPARATOR . 'public_html', 0o750, true);

        $this->originalBasePath = getenv('PULSAR_BASE_PATH');
        putenv('PULSAR_BASE_PATH=' . $this->base);
    }

    protected function tearDown(): void
    {
        if ($this->originalBasePath === false) {
            putenv('PULSAR_BASE_PATH');
        } else {
            putenv('PULSAR_BASE_PATH=' . $this->originalBasePath);
        }

        @rmdir($this->base . DIRECTORY_SEPARATOR . 'public');
        @rmdir($this->base . DIRECTORY_SEPARATOR . 'public_html');
        @rmdir($this->base);
    }

    #[Test]
    public function aStatePathOutsideTheWebrootResolvesToAnAbsolutePath(): void
    {
        // var/cache resolves to <base>/var/cache — outside <base>/public.
        self::assertSame(
            $this->base . DIRECTORY_SEPARATOR . 'var/cache',
            WritablePathGuard::resolveState('var/cache', 'cache.path'),
        );
    }

    #[Test]
    public function aStatePathInsideTheWebrootIsRefused(): void
    {
        $this->expectException(UnsafeWritablePathException::class);
        $this->expectExceptionMessageMatches('/document root/');

        (void) WritablePathGuard::resolveState('public/var/cache', 'cache.path');
    }

    #[Test]
    public function aSiblingSharingTheWebrootNamePrefixIsNotRefused(): void
    {
        // public_html must NOT be treated as inside public — a raw str_starts_with
        // check would wrongly reject it.
        self::assertSame(
            $this->base . DIRECTORY_SEPARATOR . 'public_html/cache',
            WritablePathGuard::resolveState('public_html/cache', 'cache.path'),
        );
    }

    #[Test]
    public function aTraversalBackIntoTheWebrootIsRefused(): void
    {
        // Resolves under public via '..' — the realpath/ancestor idiom catches it
        // where a raw prefix check would not.
        $this->expectException(UnsafeWritablePathException::class);

        (void) WritablePathGuard::resolveState('var/../public/cache', 'cache.path');
    }

    #[Test]
    public function anAbsolutePathOutsideTheTreePassesUntouched(): void
    {
        $external = DIRECTORY_SEPARATOR . 'mnt' . DIRECTORY_SEPARATOR . 'state' . DIRECTORY_SEPARATOR . 'logs';

        self::assertSame($external, WritablePathGuard::resolveState($external, 'compliance_logging.path'));
    }
}
