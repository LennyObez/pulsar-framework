<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Media;

use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Exception\CmsException;

use function dirname;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function str_contains;
use function str_starts_with;
use function unlink;

use const LOCK_EX;

/**
 * Local filesystem implementation of the media storage disk.
 *
 * Files are stored under a base path with secure path validation
 * to prevent directory traversal attacks.
 */
#[Internal(reason: 'Use MediaDiskInterface for public API')]
final readonly class LocalDisk implements MediaDiskInterface
{
    public function __construct(
        private string $basePath,
    ) {}

    public function write(string $path, string $contents): void
    {
        $fullPath = $this->resolvePath($path);
        $directory = dirname($fullPath);

        if (!is_dir($directory)) {
            mkdir($directory, 0o755, true);
        }

        file_put_contents($fullPath, $contents, LOCK_EX);
    }

    public function read(string $path): string
    {
        $fullPath = $this->resolvePath($path);

        if (!file_exists($fullPath)) {
            throw CmsException::mediaNotFound($path);
        }

        $contents = file_get_contents($fullPath);

        if ($contents === false) {
            throw CmsException::mediaNotFound($path);
        }

        return $contents;
    }

    public function delete(string $path): void
    {
        $fullPath = $this->resolvePath($path);

        if (file_exists($fullPath)) {
            unlink($fullPath);
        }
    }

    public function exists(string $path): bool
    {
        return file_exists($this->resolvePath($path));
    }

    public function url(string $path): string
    {
        return '/media/' . ltrim($path, '/');
    }

    /**
     * Resolve a relative path to an absolute path with traversal protection.
     *
     * @throws CmsException If the path contains directory traversal or is absolute
     */
    private function resolvePath(string $path): string
    {
        if (str_contains($path, '..')) {
            throw CmsException::mediaNotFound($path);
        }

        // Reject absolute paths (Unix and Windows)
        if (str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('/^[A-Za-z]:/', $path)) {
            throw CmsException::mediaNotFound($path);
        }

        return rtrim($this->basePath, '/\\') . '/' . $path;
    }
}
