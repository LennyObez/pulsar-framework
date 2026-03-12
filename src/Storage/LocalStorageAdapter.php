<?php

declare(strict_types=1);

namespace Pulsar\Storage;

use NoDiscard;
use Override;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

use function dirname;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function is_file;
use function mkdir;
use function realpath;
use function stat;
use function str_contains;
use function str_starts_with;
use function strlen;
use function substr;

use const DIRECTORY_SEPARATOR;
use const LOCK_EX;

/**
 * Local filesystem storage adapter.
 *
 * Maps storage keys to files under a base path with path traversal prevention.
 * Path traversal via `..` is blocked, but symlinks within the base path are
 * followed. Deployers who allow untrusted file uploads should ensure the base
 * path contains no symlinks that escape the intended storage boundary.
 */
final readonly class LocalStorageAdapter implements StorageAdapterInterface
{
    private string $basePath;

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '/\\');
    }

    #[Override]
    public function put(string $key, string $content, ?StorageMetadata $metadata = null): void
    {
        $path = $this->resolvePath($key);
        $dir = dirname($path);

        if (!is_dir($dir) && !mkdir($dir, 0o750, true) && !is_dir($dir)) {
            throw StorageException::writeFailed($key, 'failed to create directory');
        }

        $result = file_put_contents($path, $content, LOCK_EX);

        if ($result === false) {
            throw StorageException::writeFailed($key, 'file_put_contents failed');
        }
    }

    #[Override]
    #[NoDiscard]
    public function get(string $key): string
    {
        $path = $this->resolvePath($key);

        if (!is_file($path)) {
            throw StorageException::objectNotFound($key);
        }

        $content = file_get_contents($path);

        if ($content === false) {
            throw StorageException::readFailed($key, 'file_get_contents failed');
        }

        return $content;
    }

    #[Override]
    public function exists(string $key): bool
    {
        return is_file($this->resolvePath($key));
    }

    #[Override]
    public function delete(string $key): void
    {
        $path = $this->resolvePath($key);

        if (!file_exists($path)) {
            return;
        }

        // F3.3 / F17.4: route the destructive op through Symfony's
        // Filesystem (a secure-by-default library). The path was
        // already validated by resolvePath() — basePath prefix +
        // realpath symlink-escape rejection — so the only remaining
        // failure is genuine I/O (permission denied, disk error).
        try {
            new Filesystem()->remove($path);
        } catch (IOException $e) {
            throw StorageException::deleteFailed($key, $e->getMessage());
        }
    }

    #[Override]
    public function list(string $prefix = ''): array
    {
        $dir = $prefix !== '' ? $this->resolvePath($prefix) : $this->basePath;

        if (!is_dir($dir)) {
            return [];
        }

        $objects = [];
        $this->scanDirectory($dir, $this->basePath, $objects);

        return $objects;
    }

    #[Override]
    public function temporaryUrl(string $key, int $expiresInSeconds = 3600): ?string
    {
        return null;
    }

    private function resolvePath(string $key): string
    {
        $this->validateKey($key);

        $candidate = $this->basePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $key);

        // F17.4: validateKey blocks `..` segments at the string level,
        // but a symlink inside basePath that points outside basePath
        // would still let read/write/delete escape the storage
        // boundary. Resolve realpath against the basePath realpath
        // and reject when the resolved path leaves the boundary.
        // We only check this when the path already exists — paths-
        // to-create still need the prefix check on the parent.
        $realBase = realpath($this->basePath);
        if ($realBase === false) {
            return $candidate;
        }

        $realCandidate = realpath($candidate);
        if ($realCandidate !== false && !str_starts_with($realCandidate, $realBase)) {
            throw StorageException::invalidKey($key, 'symlink escapes storage base path');
        }

        return $candidate;
    }

    private function validateKey(string $key): void
    {
        if ($key === '') {
            throw StorageException::invalidKey($key, 'key must not be empty');
        }

        if (str_contains($key, '..')) {
            throw StorageException::invalidKey($key, 'path traversal not allowed');
        }

        if (str_starts_with($key, '/') || str_starts_with($key, '\\')) {
            throw StorageException::invalidKey($key, 'key must not start with a directory separator');
        }
    }

    /**
     * @param list<StorageObject> $objects
     */
    private function scanDirectory(string $dir, string $basePath, array &$objects): void
    {
        $entries = scandir($dir);

        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $fullPath = $dir . DIRECTORY_SEPARATOR . $entry;

            if (is_dir($fullPath)) {
                $this->scanDirectory($fullPath, $basePath, $objects);
                continue;
            }

            if (!is_file($fullPath)) {
                continue;
            }

            $relativeKey = substr($fullPath, strlen($basePath) + 1);
            $relativeKey = str_replace('\\', '/', $relativeKey);

            $fileStat = @stat($fullPath);

            if ($fileStat === false) {
                continue;
            }

            $objects[] = new StorageObject(
                key: $relativeKey,
                size: $fileStat['size'],
                lastModified: $fileStat['mtime'],
            );
        }
    }
}
