<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Filesystem;

use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Filesystem\SafeFilesystem;
use Pulsar\Filesystem\SafePath;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

use function file_put_contents;
use function is_dir;
use function mkdir;
use function sprintf;
use function sys_get_temp_dir;
use function uniqid;

/**
 * Covers {@see SafeFilesystem::rmdirWithRetry()} escalating-backoff
 * retry path and the best-effort {@see SafeFilesystem::removeFile()}
 * IOException swallow — the only non-trivial logic in the class and
 * previously untested.
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
        if ($this->root !== '' && is_dir($this->root)) {
            // Clean up with the real component; the fake under test
            // never actually deletes, so the leaf directory remains.
            new Filesystem()->remove($this->root);
        }
    }

    #[Test]
    public function rmdirRetriesThenSucceedsAfterTransientIoException(): void
    {
        // Fail only the first attempt, then "succeed" (no-op).
        $fs = new CountingThrowingFilesystem(throwUpTo: 1);

        new SafeFilesystem($fs)->removeDirectoryRecursive($this->safeRoot());

        // One failed attempt + one successful retry = exactly 2 calls;
        // the loop must stop on success and not exhaust the schedule.
        self::assertSame(2, $fs->calls);
    }

    #[Test]
    public function rmdirExhaustsScheduleThenSwallowsPersistentIoException(): void
    {
        // Throw on every call so the whole schedule is exercised.
        $fs = new CountingThrowingFilesystem(throwUpTo: PHP_INT_MAX);

        // Must not propagate despite every attempt failing.
        new SafeFilesystem($fs)->removeDirectoryRecursive($this->safeRoot());

        // 5 backoff attempts + 1 final best-effort attempt = 6 calls.
        self::assertSame(6, $fs->calls);
    }

    #[Test]
    public function removeFileSwallowsIoException(): void
    {
        $leaf = $this->root . DIRECTORY_SEPARATOR . 'leaf.txt';
        file_put_contents($leaf, 'x');

        $fs = new CountingThrowingFilesystem(throwUpTo: PHP_INT_MAX);

        $safe = SafePath::resolveUnder('leaf.txt', $this->root);
        self::assertNotNull($safe);

        // Best-effort: a failed leaf removal must not throw.
        new SafeFilesystem($fs)->removeFile($safe);

        self::assertSame(1, $fs->calls);
    }

    private function safeRoot(): SafePath
    {
        // An empty real directory: removeDirectoryRecursive() finds no
        // children and proceeds straight to rmdirWithRetry().
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

/**
 * Test double that counts {@see Filesystem::remove()} calls and throws
 * an {@see IOException} for the first `$throwUpTo` invocations, then
 * becomes a no-op. It never touches the real filesystem so callers can
 * assert exact retry counts deterministically.
 */
final class CountingThrowingFilesystem extends Filesystem
{
    public int $calls = 0;

    public function __construct(private readonly int $throwUpTo) {}

    /**
     * @param string|iterable<string> $files
     */
    #[Override]
    public function remove(string|iterable $files): void
    {
        ++$this->calls;
        if ($this->calls <= $this->throwUpTo) {
            throw new IOException('simulated filesystem lock');
        }
        // Past the throw window: intentionally a no-op so the leaf
        // directory survives for deterministic assertions.
    }
}
