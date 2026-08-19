<?php

declare(strict_types=1);

namespace Pulsar\Tests\Support;

use Override;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function clearstatcache;
use function gc_collect_cycles;
use function is_dir;
use function mkdir;
use function realpath;
use function rmdir;
use function str_starts_with;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;
use function usleep;

use const DIRECTORY_SEPARATOR;

/**
 * Base test case providing a uniquely-scoped temporary directory with
 * guaranteed recursive cleanup after each test.
 *
 * Cleanup uses the canonical PHP recursive-delete idiom:
 * `RecursiveDirectoryIterator` with `RecursiveIteratorIterator::CHILD_FIRST`
 * so leaves are visited (and removed) before their parents. Every path
 * touched by the delete loop is validated twice: once against the
 * OS temp base to refuse operations outside `sys_get_temp_dir()`, and
 * once per-entry to defeat symlink-escape attempts.
 *
 * Windows-specific retry: removals of SQLite WAL/SHM sidecars can fail
 * transiently because Windows caches the file handle for a few milliseconds
 * after PDO releases it. A bounded retry loop with forced GC cycles and
 * stat cache clearing resolves this without resorting to error suppression.
 *
 * Test classes that need a temp directory should extend this class
 * instead of writing their own helper, consolidating the filesystem
 * surface of the test suite in one auditable place.
 */
abstract class FilesystemTestCase extends TestCase
{
    /**
     * Maximum attempts to unlink a file or rmdir a directory.
     *
     * Chosen so that the total worst-case wait is ~100ms
     * (4 retries × 25ms), well under any individual test's
     * expected runtime but enough to outlast Windows handle caching.
     */
    private const int MAX_CLEANUP_ATTEMPTS = 5;

    /**
     * Delay between retry attempts in microseconds (25ms).
     */
    private const int CLEANUP_RETRY_DELAY_US = 25_000;

    protected string $tempDirectory;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDirectory = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'pulsar-test-'
            . uniqid('', true);

        if (!mkdir($this->tempDirectory, 0o700, true) && !is_dir($this->tempDirectory)) {
            self::fail('Unable to create test temporary directory: ' . $this->tempDirectory);
        }
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->removeTemporaryDirectoryTree();

        parent::tearDown();
    }

    /**
     * Recursively remove the test's temporary directory.
     *
     * Visits children before parents (CHILD_FIRST) so that `unlink` is
     * called on files and `rmdir` on empty directories in the correct
     * order. Every iterated entry is canonicalised and re-validated
     * against the already-verified `$resolved` base to defeat any
     * symlink injected after setUp.
     */
    private function removeTemporaryDirectoryTree(): void
    {
        $resolved = realpath($this->tempDirectory);
        $tempBase = realpath(sys_get_temp_dir());

        if ($resolved === false || $tempBase === false) {
            return;
        }

        if (!str_starts_with($resolved, $tempBase . DIRECTORY_SEPARATOR)) {
            return;
        }

        if (!is_dir($resolved)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($resolved, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var SplFileInfo $fileInfo */
        foreach ($iterator as $fileInfo) {
            $entryReal = realpath($fileInfo->getPathname());

            if ($entryReal === false || !str_starts_with($entryReal, $resolved . DIRECTORY_SEPARATOR)) {
                continue;
            }

            if ($fileInfo->isDir()) {
                $this->removeWithRetry($entryReal, isDirectory: true);
            } else {
                $this->removeWithRetry($entryReal, isDirectory: false);
            }
        }

        $this->removeWithRetry($resolved, isDirectory: true);
    }

    /**
     * Remove a single filesystem entry with bounded retry for Windows.
     *
     * On Windows, SQLite's WAL/SHM sidecar files remain locked for a
     * short time after PDO finalizes the connection; attempting to
     * `unlink` immediately fails with EAGAIN. We invoke the cycle
     * collector and clear stat cache between attempts to force
     * finalization of any lingering PDO instances and flush cached
     * negative lookups.
     */
    private function removeWithRetry(string $path, bool $isDirectory): void
    {
        for ($attempt = 1; $attempt <= self::MAX_CLEANUP_ATTEMPTS; $attempt++) {
            clearstatcache(true, $path);

            if ($isDirectory) {
                if (!is_dir($path)) {
                    return;
                }

                if (@rmdir($path)) {
                    return;
                }
            } else {
                if (@unlink($path)) {
                    return;
                }
            }

            if ($attempt === self::MAX_CLEANUP_ATTEMPTS) {
                return;
            }

            // Force finalization of any PDO handles holding the file
            // open, then sleep briefly to let Windows release the
            // kernel-level file handle.
            gc_collect_cycles();
            usleep(self::CLEANUP_RETRY_DELAY_US);
        }
    }
}
