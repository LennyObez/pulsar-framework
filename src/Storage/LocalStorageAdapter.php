<?php

declare(strict_types=1);

namespace Pulsar\Storage;

use const DIRECTORY_SEPARATOR;

use function dirname;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function filemtime;
use function filesize;
use function is_dir;
use function is_file;

use const LOCK_EX;

use function mkdir;
use function str_contains;
use function str_starts_with;
use function strlen;
use function substr;
use function unlink;

/**
 * Local filesystem storage adapter.
 *
 * Maps storage keys to files under a base path with path traversal prevention.
 */
final readonly class LocalStorageAdapter implements StorageAdapterInterface
{
    private readonly string $basePath;

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '/\\');
    }

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

    public function exists(string $key): bool
    {
        return is_file($this->resolvePath($key));
    }

    public function delete(string $key): void
    {
        $path = $this->resolvePath($key);

        if (!file_exists($path)) {
            return;
        }

        if (!unlink($path)) {
            throw StorageException::deleteFailed($key, 'unlink failed');
        }
    }

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

    public function temporaryUrl(string $key, int $expiresInSeconds = 3600): ?string
    {
        return null;
    }

    private function resolvePath(string $key): string
    {
        $this->validateKey($key);

        return $this->basePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $key);
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

            $objects[] = new StorageObject(
                key: $relativeKey,
                size: (int) filesize($fullPath),
                lastModified: (int) filemtime($fullPath),
            );
        }
    }
}
