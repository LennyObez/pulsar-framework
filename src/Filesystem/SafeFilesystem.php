<?php

declare(strict_types=1);

namespace Pulsar\Filesystem;

use Closure;
use Pulsar\Api\Api;

use function clearstatcache;
use function gc_collect_cycles;
use function is_dir;
use function is_file;
use function restore_error_handler;
use function rmdir;
use function scandir;
use function set_error_handler;
use function unlink;
use function usleep;

/**
 * Filesystem helper that only operates on {@see SafePath} arguments.
 *
 * Every destructive operation (file remove, directory remove)
 * goes through this class with a SafePath input. The SafePath value
 * object guarantees the path lives under a trust boundary, so the
 * operations cannot touch the filesystem outside the project root
 * even with a hostile `--path` option, a `..` traversal segment, or
 * a mid-tree symlink.
 *
 * The destructive primitives are native `unlink()` / `rmdir()` calls
 * reached only through this class and only with a `SafePath` argument,
 * so every deletion in the scaffold layer lives behind the trust-boundary
 * chokepoint. The transient-failure warning (a held handle, a lost race)
 * is swallowed with a scoped error handler — never the `@` operator — and
 * surfaced as a boolean so the retry loop can act on it.
 * @api
 */
#[Api(since: '1.0.0')]
final class SafeFilesystem
{
    /** @var Closure(string): bool */
    private readonly Closure $remove;

    /**
     * @param ?Closure(string): bool $remove Deletion primitive returning
     *   whether the path was removed. Defaults to a native unlink/rmdir that
     *   swallows the transient-failure warning. Injectable only to drive the
     *   retry / best-effort tests deterministically; applications use the
     *   default and never pass this argument.
     */
    public function __construct(?Closure $remove = null)
    {
        $this->remove = $remove ?? self::nativeRemove(...);
    }

    /**
     * Recursively remove a directory and all its contents.
     *
     * Includes retry logic for platforms where a file handle may not be
     * released immediately after removal — Windows in particular, and any
     * filesystem behind an antivirus scanner or a cloud-sync file provider.
     */
    public function removeDirectoryRecursive(SafePath $dir): void
    {
        $path = $dir->absolute;

        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            // Re-validate every iteration so a symlink mid-tree
            // cannot redirect the descent outside the boundary.
            $child = $dir->child($item);
            if ($child === null) {
                continue;
            }

            if (is_dir($child->absolute)) {
                $this->removeDirectoryRecursive($child);
            } else {
                $this->removeFile($child);
            }
        }

        // Release scandir handle references so Windows can free the directory
        unset($items);
        gc_collect_cycles();
        clearstatcache(true, $path);

        $this->rmdirWithRetry($path);
    }

    /**
     * Remove a single file. The SafePath argument is the chokepoint
     * that satisfies the security review: any caller wanting to
     * remove a file must first build a `SafePath`, which in turn
     * validates the path-traversal / symlink-escape pre-conditions.
     */
    public function removeFile(SafePath $path): void
    {
        $abs = $path->absolute;

        if (!is_file($abs)) {
            return;
        }

        // Best-effort: scaffold removal is intentionally non-fatal, so a single
        // failed leaf does not abort the broader operation (the caller retries
        // at the rmdir level). The SafePath argument is the chokepoint that
        // guarantees $abs lives inside the trust boundary.
        ($this->remove)($abs);
    }

    /**
     * List all files under a SafePath directory recursively, returning
     * SafePath instances so downstream callers stay inside the
     * trust boundary.
     *
     * @return list<SafePath>
     */
    public function listFilesRecursive(SafePath $dir): array
    {
        $path = $dir->absolute;

        if (!is_dir($path)) {
            return [];
        }

        $items = scandir($path);
        if ($items === false) {
            return [];
        }

        $files = [];

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $child = $dir->child($item);
            if ($child === null) {
                continue;
            }

            if (is_dir($child->absolute)) {
                foreach ($this->listFilesRecursive($child) as $nested) {
                    $files[] = $nested;
                }
            } else {
                $files[] = $child;
            }
        }

        return $files;
    }

    private function rmdirWithRetry(string $dir): void
    {
        // Escalating delays: 100ms, 200ms, 400ms, 800ms, 1600ms (~3.1s total).
        // Tolerates the brief handle-release lag on Windows and on any
        // filesystem behind an antivirus scanner or cloud-sync file provider.
        $delays = [100_000, 200_000, 400_000, 800_000, 1_600_000];

        foreach ($delays as $delay) {
            if (($this->remove)($dir)) {
                return;
            }

            usleep($delay);
            clearstatcache(true, $dir);
        }

        // Final best-effort attempt. A directory still not removable after the
        // full backoff schedule is left in place rather than crashing the whole
        // remove operation; scaffold remove is intentionally non-fatal.
        ($this->remove)($dir);
    }

    /**
     * Native deletion primitive: rmdir for a directory, unlink otherwise.
     * The transient-failure warning (a held handle, a lost race) is swallowed
     * via a scoped error handler — never the `@` operator — and reported
     * through the boolean return so {@see rmdirWithRetry()} can retry.
     */
    private static function nativeRemove(string $path): bool
    {
        set_error_handler(static fn(): bool => true);

        try {
            return is_dir($path) ? rmdir($path) : unlink($path);
        } finally {
            restore_error_handler();
        }
    }
}
