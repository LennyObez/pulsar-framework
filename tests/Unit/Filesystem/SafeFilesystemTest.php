<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Filesystem;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Filesystem\SafeFilesystem;
use Pulsar\Filesystem\SafePath;

use function basename;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function sprintf;
use function sys_get_temp_dir;
use function uniqid;

use const DIRECTORY_SEPARATOR;

/**
 * Covers {@see SafeFilesystem::rmdirWithRetry()} escalating-backoff retry path
 * and the best-effort {@see SafeFilesystem::removeFile()} swallow — the only
 * non-trivial logic in the class. The native deletion primitive is injected as
 * a counting closure so retry counts are asserted deterministically without
 * touching the real filesystem.
 */
#[CoversClass(SafeFilesystem::class)]
final class SafeFilesystemTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . sprintf('pulsar_sfs_%s', uniqid('', true));

        if (!mkdir($base, 0o750, true) && !is_dir($base)) {
            self::markTestSkipped('Could not create temp directory for SafeFilesystem test.');
        }

        $this->root = $base;
    }

    protected function tearDown(): void
    {
        if ($this->root === '' || !is_dir($this->root)) {
            return;
        }

        // Clean up with the real component (default native remover); the
        // injected counting closures under test never actually delete, so the
        // tree is left behind.
        $safe = SafePath::resolveUnder(basename($this->root), sys_get_temp_dir());
        if ($safe !== null) {
            new SafeFilesystem()->removeDirectoryRecursive($safe);
        }
    }

    #[Test]
    public function rmdirRetriesThenSucceedsAfterTransientFailure(): void
    {
        $calls = 0;
        // Fail only the first attempt, then "succeed" (no real deletion).
        $remove = static function (string $path) use (&$calls): bool {
            ++$calls;

            return $calls > 1;
        };

        new SafeFilesystem($remove)->removeDirectoryRecursive($this->safeRoot());

        // One failed attempt + one successful retry = exactly 2 calls; the loop
        // must stop on success and not exhaust the schedule.
        self::assertSame(2, $calls);
    }

    #[Test]
    public function rmdirExhaustsScheduleThenSwallowsPersistentFailure(): void
    {
        $calls = 0;
        // Never succeed so the whole backoff schedule is exercised.
        $remove = static function (string $path) use (&$calls): bool {
            ++$calls;

            return false;
        };

        // Must not propagate despite every attempt failing.
        new SafeFilesystem($remove)->removeDirectoryRecursive($this->safeRoot());

        // 5 backoff attempts + 1 final best-effort attempt = 6 calls.
        self::assertSame(6, $calls);
    }

    #[Test]
    public function removeFileSwallowsFailure(): void
    {
        $leaf = $this->root . DIRECTORY_SEPARATOR . 'leaf.txt';
        file_put_contents($leaf, 'x');

        $calls = 0;
        $remove = static function (string $path) use (&$calls): bool {
            ++$calls;

            return false;
        };

        $safe = SafePath::resolveUnder('leaf.txt', $this->root);
        self::assertNotNull($safe);

        // Best-effort: a failed leaf removal must not throw.
        new SafeFilesystem($remove)->removeFile($safe);

        self::assertSame(1, $calls);
    }

    private function safeRoot(): SafePath
    {
        // An empty real directory: removeDirectoryRecursive() finds no children
        // and proceeds straight to rmdirWithRetry().
        $child = 'dir_' . uniqid('', true);
        $full = $this->root . DIRECTORY_SEPARATOR . $child;
        if (!mkdir($full, 0o750) && !is_dir($full)) {
            self::markTestSkipped('Could not create child temp directory.');
        }

        $safe = SafePath::resolveUnder($child, $this->root);
        self::assertNotNull($safe);

        return $safe;
    }
}
