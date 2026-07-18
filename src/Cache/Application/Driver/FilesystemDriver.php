<?php

declare(strict_types=1);

namespace Pulsar\Cache\Application\Driver;

use Pulsar\Api\Internal;
use Random\Engine\Secure;
use Random\Randomizer;

use function dirname;
use function fclose;
use function fflush;
use function file_get_contents;
use function file_put_contents;
use function filemtime;
use function flock;
use function fopen;
use function ftruncate;
use function fwrite;
use function getmypid;
use function glob;
use function hash;
use function hrtime;
use function is_array;
use function is_dir;
use function is_file;
use function is_int;
use function is_string;
use function mkdir;
use function rename;
use function rewind;
use function rmdir;
use function serialize;
use function stream_get_contents;
use function substr;
use function time;
use function unlink;
use function unserialize;

use const DIRECTORY_SEPARATOR;
use const GLOB_NOSORT;
use const LOCK_EX;
use const LOCK_UN;

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
    /**
     * Age (seconds) past which an orphaned atomic-write temp file is assumed to
     * be from an interrupted write and is swept by {@see gc()}.
     */
    private const int STALE_TEMP_FILE_AGE_SECONDS = 3600;

    private readonly Randomizer $randomizer;

    /**
     * @param string $directory Root cache directory.
     * @param int $gcDivisor Garbage-collection lottery: on each write there is a
     *     1-in-$gcDivisor chance of sweeping expired entries (like PHP's
     *     `session.gc_divisor`). Expired entries are otherwise only reclaimed
     *     lazily on read, so a key written once and never read again would leak
     *     on disk forever. Set to 0 to disable the lottery — e.g. when a
     *     scheduled job calls {@see gc()} instead.
     */
    public function __construct(
        private readonly string $directory,
        private readonly int $gcDivisor = 100,
        ?Randomizer $randomizer = null,
    ) {
        $this->randomizer = $randomizer ?? new Randomizer(new Secure());
    }

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

        $written = $this->atomicWrite($path, $entry);

        // Garbage-collection lottery: occasionally reclaim expired entries that
        // no read will ever touch, so the cache directory does not grow without
        // bound. Kept rare so the sweep cost is amortized across many writes.
        if ($written && $this->gcDivisor > 0 && $this->randomizer->getInt(1, $this->gcDivisor) === 1) {
            $this->gc();
        }

        return $written;
    }

    /**
     * Atomic conditional store (SETNX): write only if the key is absent.
     *
     * Overrides the non-atomic has()-then-set() default with a genuine
     * same-host atomic operation: an exclusive `flock` serialises every add()
     * on the key, and the presence check is re-evaluated UNDER the lock — an
     * existing-but-expired entry counts as absent, so it can be claimed. This is
     * what a fixed-window rate limiter needs: two PHP-FPM workers racing to
     * create the same window no longer both "succeed". Correct for the
     * single-server deployments this driver targets (ADR-0018); flock semantics
     * across NFS are not relied on.
     */
    public function add(string $key, string $value, ?int $ttlSeconds): bool
    {
        $ttl = $this->normalizeTtl($ttlSeconds);

        $handle = $this->openLocked($key);

        if ($handle === null) {
            return false;
        }

        try {
            if ($this->readLiveEntry($handle) !== null) {
                // Present and unexpired under the lock — the slot is taken.
                return false;
            }

            $this->writeEntry($handle, $value, $ttl !== null ? time() + $ttl : null);

            return true;
        } finally {
            $this->releaseLocked($handle);
        }
    }

    public function increment(string $key, int $step = 1): int|false
    {
        return $this->applyDelta($key, $step);
    }

    public function decrement(string $key, int $step = 1): int|false
    {
        return $this->applyDelta($key, -$step);
    }

    /**
     * Atomic read-modify-write of an integer counter.
     *
     * Same locking discipline as {@see add()}. Preserves the existing entry's
     * expiry (Redis INCR semantics: the TTL survives the increment); an absent
     * or expired key starts at 0 with no expiry. A non-integer value is refused
     * (returns false), as Redis does.
     */
    private function applyDelta(string $key, int $delta): int|false
    {
        $handle = $this->openLocked($key);

        if ($handle === null) {
            return false;
        }

        try {
            $entry = $this->readLiveEntry($handle);

            if ($entry === null) {
                $current = 0;
                $expiresAt = null;
            } else {
                $value = $entry['value'];

                // Refuse a value that is not a canonical base-10 integer.
                if ($value !== (string) (int) $value) {
                    return false;
                }

                $current = (int) $value;
                $expiresAt = $entry['expiresAt'];
            }

            $new = $current + $delta;

            $this->writeEntry($handle, (string) $new, $expiresAt);

            return $new;
        } finally {
            $this->releaseLocked($handle);
        }
    }

    /**
     * Open the key's file with an exclusive advisory lock held, creating it (and
     * its shard directory) if absent. Returns the locked handle, or null when the
     * directory or file could not be opened/locked.
     *
     * @return resource|null
     */
    private function openLocked(string $key)
    {
        $path = $this->path($key);
        $dir = dirname($path);

        // @: a concurrent openLocked() for a sibling key can create the shard
        // dir between the is_dir check and mkdir — the trailing is_dir() makes
        // that benign, but silence the harmless "File exists" warning.
        if (!is_dir($dir) && !@mkdir($dir, 0o750, true) && !is_dir($dir)) {
            return null;
        }

        // c+b: read/write, create if missing, do NOT truncate, pointer at start.
        $handle = @fopen($path, 'c+b');

        if ($handle === false) {
            return null;
        }

        if (!flock($handle, LOCK_EX)) {
            fclose($handle);

            return null;
        }

        return $handle;
    }

    /**
     * @param resource $handle
     */
    private function releaseLocked($handle): void
    {
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    /**
     * The live (unexpired) entry from a locked handle, or null when the file is
     * empty, unparseable, or expired. Reads from the start without moving the
     * caller's subsequent write position expectation (writeEntry rewinds).
     *
     * @param resource $handle
     *
     * @return array{value: string, expiresAt: int|null}|null
     */
    private function readLiveEntry($handle): ?array
    {
        rewind($handle);
        $raw = stream_get_contents($handle);

        if ($raw === false || $raw === '') {
            return null;
        }

        $entry = @unserialize($raw, ['allowed_classes' => false]);

        if (!is_array($entry) || !isset($entry['value'])) {
            return null;
        }

        $value = $entry['value'];
        $expiresAt = $entry['expiresAt'] ?? null;

        if (!is_string($value) || ($expiresAt !== null && !is_int($expiresAt))) {
            return null;
        }

        if ($expiresAt !== null && $expiresAt <= time()) {
            return null;
        }

        return ['value' => $value, 'expiresAt' => $expiresAt];
    }

    /**
     * Overwrite a locked handle's contents in place with a fresh entry.
     *
     * @param resource $handle
     */
    private function writeEntry($handle, string $value, ?int $expiresAt): void
    {
        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, serialize(['value' => $value, 'expiresAt' => $expiresAt]));
        fflush($handle);
    }

    /**
     * Reclaim expired cache entries and orphaned temp files across every shard.
     *
     * Expired entries are normally only removed lazily when they are next read;
     * this sweep reclaims write-only-then-expired keys that are never read
     * again. Safe to call from a scheduled job or the write-time lottery.
     *
     * @param ?int $now Reference timestamp (defaults to the current time);
     *     exposed for deterministic testing.
     *
     * @return int Number of files removed.
     */
    public function gc(?int $now = null): int
    {
        $now ??= time();

        $shardDirs = glob($this->directory . DIRECTORY_SEPARATOR . '*', GLOB_NOSORT);

        if ($shardDirs === false) {
            return 0;
        }

        $removed = 0;

        foreach ($shardDirs as $shardDir) {
            if (!is_dir($shardDir)) {
                continue;
            }

            $files = @glob($shardDir . DIRECTORY_SEPARATOR . '*', GLOB_NOSORT);

            if ($files !== false) {
                foreach ($files as $file) {
                    if ($this->isExpiredEntryFile($file, $now) && @unlink($file)) {
                        $removed++;
                    }
                }
            }

            // Orphaned atomic-write temp files (.tmp.<pid>.<hrtime>) from an
            // interrupted set() are not matched by glob('*') on Unix; sweep the
            // ones old enough to be from a crashed write rather than an in-flight
            // one.
            $tmpFiles = @glob($shardDir . DIRECTORY_SEPARATOR . '.tmp.*', GLOB_NOSORT);

            if ($tmpFiles !== false) {
                foreach ($tmpFiles as $tmpFile) {
                    $mtime = @filemtime($tmpFile);

                    if ($mtime !== false && $mtime <= $now - self::STALE_TEMP_FILE_AGE_SECONDS && @unlink($tmpFile)) {
                        $removed++;
                    }
                }
            }
        }

        return $removed;
    }

    /**
     * Whether the given path holds a cache entry whose expiry is at or before
     * the reference time. Unreadable or unparseable files are left untouched.
     */
    private function isExpiredEntryFile(string $file, int $now): bool
    {
        if (!is_file($file)) {
            return false;
        }

        $raw = @file_get_contents($file);

        if ($raw === false) {
            return false;
        }

        $entry = @unserialize($raw, ['allowed_classes' => false]);

        if (!is_array($entry)) {
            return false;
        }

        return ($entry['expiresAt'] ?? null) !== null && $entry['expiresAt'] <= $now;
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
            // Strict tags and atomic counters are backed by the same flock-based
            // read-modify-write as add()/increment(); honest now that those are
            // atomic, and it lets a filesystem pool use StrictTagStrategy rather
            // than the stale-window best-effort one.
            supportsTagsStrict: true,
            supportsBinary: true,
            supportsAtomicIncrement: true,
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
