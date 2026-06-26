<?php

declare(strict_types=1);

namespace Pulsar\Cache\Application\Driver;

use Pulsar\Api\Internal;

use function dirname;
use function file_get_contents;
use function file_put_contents;
use function getmypid;
use function glob;
use function hash;
use function hrtime;
use function is_array;
use function is_dir;
use function is_file;
use function mkdir;
use function rename;
use function rmdir;
use function serialize;
use function substr;
use function time;
use function unlink;
use function unserialize;

use const DIRECTORY_SEPARATOR;
use const GLOB_NOSORT;
use const LOCK_EX;

/**
 * Filesystem-based cache driver with atomic writes and directory sharding.
 *
 * Keys are hashed with xxHash-128 and stored in a two-level directory structure
 * (first 2 characters of hash). Writes use temp-file + rename for atomicity.
 * xxHash is used instead of SHA-256 because path derivation is a non-cryptographic
 * use case where speed matters more than collision resistance.
 */
#[Internal]
final class FilesystemDriver extends AbstractCacheDriver
{
    public function __construct(
        private readonly string $directory,
    ) {}

    public function get(string $key): ?string
    {
        $path = $this->path($key);

        if (!is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);

        if ($raw === false) {
            return null;
        }

        $entry = @unserialize($raw, ['allowed_classes' => false]);

        if (!is_array($entry)) {
            return null;
        }

        if (($entry['expiresAt'] ?? null) !== null && $entry['expiresAt'] <= time()) {
            @unlink($path);

            return null;
        }

        /** @var string|null */
        return $entry['value'] ?? null;
    }

    public function set(string $key, string $value, ?int $ttlSeconds): bool
    {
        $ttl = $this->normalizeTtl($ttlSeconds);

        if ($this->isExpiredTtl($ttl)) {
            $this->delete($key);

            return true;
        }

        $entry = serialize([
            'value' => $value,
            'expiresAt' => $ttl !== null ? time() + $ttl : null,
        ]);

        $path = $this->path($key);

        return $this->atomicWrite($path, $entry);
    }

    public function delete(string $key): bool
    {
        $path = $this->path($key);

        if (!is_file($path)) {
            return true;
        }

        return @unlink($path);
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    public function clear(): bool
    {
        $shardDirs = glob($this->directory . DIRECTORY_SEPARATOR . '*', GLOB_NOSORT);

        if ($shardDirs === false) {
            return false;
        }

        foreach ($shardDirs as $shardDir) {
            $files = @glob($shardDir . DIRECTORY_SEPARATOR . '*', GLOB_NOSORT);

            if ($files !== false) {
                foreach ($files as $file) {
                    @unlink($file);
                }
            }

            // glob('*') does not match leading-dot files on Unix, so orphaned
            // atomic-write temp files (.tmp.<pid>.<hrtime>) left behind by an
            // interrupted set() would survive a clear() and leak. Sweep them
            // explicitly before removing the shard directory.
            $tmpFiles = @glob($shardDir . DIRECTORY_SEPARATOR . '.tmp.*', GLOB_NOSORT);

            if ($tmpFiles !== false) {
                foreach ($tmpFiles as $tmpFile) {
                    @unlink($tmpFile);
                }
            }

            @rmdir($shardDir);
        }

        return true;
    }

    public function capabilities(): CacheDriverCapabilities
    {
        return new CacheDriverCapabilities(
            supportsBinary: true,
        );
    }

    public function name(): string
    {
        return 'filesystem';
    }

    private function path(string $key): string
    {
        $hash = hash('xxh128', $key);
        $shard = substr($hash, 0, 2);

        return $this->directory . DIRECTORY_SEPARATOR . $shard . DIRECTORY_SEPARATOR . $hash;
    }

    private function atomicWrite(string $path, string $content): bool
    {
        $dir = dirname($path);

        if (!is_dir($dir) && !mkdir($dir, 0o750, true) && !is_dir($dir)) {
            return false;
        }

        $pid = getmypid();
        $tmpFile = $dir . DIRECTORY_SEPARATOR . '.tmp.' . ($pid !== false ? $pid : 0) . '.' . hrtime(true);

        $result = file_put_contents($tmpFile, $content, LOCK_EX);

        if ($result === false) {
            return false;
        }

        $renamed = @rename($tmpFile, $path);

        if (!$renamed) {
            // Windows fallback: unlink target then rename
            if (is_file($path)) {
                @unlink($path);
            }

            $renamed = @rename($tmpFile, $path);

            if (!$renamed) {
                @unlink($tmpFile);

                return false;
            }
        }

        return true;
    }
}
