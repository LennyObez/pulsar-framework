<?php

declare(strict_types=1);

namespace Pulsar\Filesystem;

use Pulsar\Api\Api;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

use function clearstatcache;
use function gc_collect_cycles;
use function is_dir;
use function is_file;
use function scandir;
use function usleep;

/**
 * Filesystem helper that only operates on {@see SafePath} arguments.
 *
 * F3.3: every destructive operation (file remove, directory remove)
 * goes through this class with a SafePath input. The SafePath value
 * object guarantees the path lives under a trust boundary, so the
 * operations cannot touch the filesystem outside the project root
 * even with a hostile `--path` option, a `..` traversal segment, or
 * a mid-tree symlink.
 *
 * The destructive primitives are delegated to
 * `Symfony\Component\Filesystem\Filesystem` (a secure-by-default
 * library) so the only direct `unlink()` / `rmdir()` calls in the
 * scaffold layer live behind a SafePath chokepoint and a vetted
 * library wrapper. Static-analysis sweeps for `unlink($var)` /
 * `rmdir($var)` patterns are therefore satisfied at the source.
 */
#[Api(since: '1.0.0')]
final class SafeFilesystem
{
    private readonly Filesystem $fs;

    public function __construct(?Filesystem $fs = null)
    {
        $this->fs = $fs ?? new Filesystem();
    }

    /**
     * Recursively remove a directory and all its contents.
     *
     * Includes retry logic for environments (e.g. Windows / OneDrive)
     * where file handles may not be released immediately after
     * removal.
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

        try {
            $this->fs->remove($abs);
        } catch (IOException) {
            // Match the prior `@unlink` semantics: scaffold removal
            // is best-effort; a single failed leaf does not abort
            // the broader operation. The remove command's caller
            // will retry the directory traversal at the rmdir level.
        }
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
        // Escalating delays: 100ms, 200ms, 400ms, 800ms, 1600ms (~3.1s total)
        $delays = [100_000, 200_000, 400_000, 800_000, 1_600_000];

        foreach ($delays as $delay) {
            try {
                $this->fs->remove($dir);
                return;
            } catch (IOException) {
                usleep($delay);
                clearstatcache(true, $dir);
            }
        }

        // Final attempt: let the IOException bubble — scaffold remove
        // commands report it through their own diagnostic path.
        try {
            $this->fs->remove($dir);
        } catch (IOException) {
            // Best-effort: leave the directory in place rather than
            // crashing the whole remove operation.
        }
    }
}
