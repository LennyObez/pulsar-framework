<?php

declare(strict_types=1);

namespace Pulsar\Cache\Application\Lock;

use Pulsar\Api\Internal;
use Pulsar\Cache\Application\Exception\LockAcquisitionException;
use Pulsar\Runtime\Fiber\CooperativeSleep;
use Random\Engine\Secure;
use Random\Randomizer;

use function bin2hex;
use function fclose;
use function flock;
use function fopen;
use function fread;
use function fseek;
use function ftell;
use function ftruncate;
use function fwrite;
use function hash;
use function hash_equals;
use function is_dir;
use function is_link;
use function json_decode;
use function json_encode;
use function microtime;
use function mkdir;
use function unlink;

use const JSON_THROW_ON_ERROR;
use const LOCK_EX;
use const LOCK_NB;
use const LOCK_UN;
use const SEEK_END;

/**
 * Filesystem-based lock using flock().
 */
#[Internal]
final class FilesystemLock implements LockInterface
{
    /** @var array<string, resource> */
    private array $handles = [];

    private readonly Randomizer $randomizer;

    public function __construct(
        private readonly string $directory,
        ?Randomizer $randomizer = null,
    ) {
        $this->randomizer = $randomizer ?? new Randomizer(new Secure());

        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0o700, true);
        }
    }

    public function acquire(string $resource, int $ttlSeconds = 30, int $timeoutMs = 0): LockHandle
    {
        $path = $this->lockPath($resource);
        $deadlineNs = hrtime(true) + ($timeoutMs * 1_000_000);

        do {
            // Reject a pre-planted symlink at the lock path before opening:
            // 'c+' follows symlinks, so an attacker who can predict the path
            // could otherwise redirect the lock-metadata write to an arbitrary
            // target. Mirrors CacheIntegrity::validateFile()'s symlink guard.
            if (is_link($path)) {
                throw LockAcquisitionException::unavailable($resource, 'Lock path is a symlink');
            }

            $handle = fopen($path, 'c+');

            if ($handle === false) {
                throw LockAcquisitionException::unavailable($resource, 'Unable to open lock file');
            }

            if (flock($handle, LOCK_EX | LOCK_NB)) {
                $token = bin2hex($this->randomizer->getBytes(16));
                $metadata = json_encode([
                    'token' => $token,
                    'expiresAt' => microtime(true) + (float) $ttlSeconds,
                ], JSON_THROW_ON_ERROR);

                ftruncate($handle, 0);
                fseek($handle, 0);
                fwrite($handle, $metadata);

                $this->handles[$resource] = $handle;

                return new LockHandle(
                    resource: $resource,
                    token: $token,
                    acquiredAt: microtime(true),
                    ttlSeconds: $ttlSeconds,
                );
            }

            fclose($handle);

            if ($timeoutMs === 0) {
                throw LockAcquisitionException::timeout($resource, $timeoutMs);
            }

            // Yield the worker to other connections while waiting, instead of
            // freezing every fiber (and the lock holder) in a blocking usleep.
            CooperativeSleep::forMilliseconds(10);
        } while (hrtime(true) < $deadlineNs);

        throw LockAcquisitionException::timeout($resource, $timeoutMs);
    }

    public function release(LockHandle $handle): bool
    {
        if (!isset($this->handles[$handle->resource])) {
            return false;
        }

        $fileHandle = $this->handles[$handle->resource];
        $metadata = $this->readMetadata($fileHandle);

        if ($metadata === null || !hash_equals($metadata['token'], $handle->token)) {
            return false;
        }

        // The lock file is intentionally NOT unlinked. Deleting it here opens a
        // race: between LOCK_UN and unlink a second process can acquire LOCK_EX
        // on the same inode, and once it is unlinked a third process opens a new
        // inode at the now-vacant path and locks that — two holders, mutual
        // exclusion broken. Keeping a persistent lock file (as CacheLock does)
        // pins the inode; acquire() reuses it via 'c+' and overwrites stale
        // metadata. The file count is bounded by the number of lock resources.
        flock($fileHandle, LOCK_UN);
        fclose($fileHandle);
        unset($this->handles[$handle->resource]);

        return true;
    }

    public function refresh(LockHandle $handle, int $ttlSeconds = 30): bool
    {
        if (!isset($this->handles[$handle->resource])) {
            return false;
        }

        $fileHandle = $this->handles[$handle->resource];
        $metadata = $this->readMetadata($fileHandle);

        if ($metadata === null || !hash_equals($metadata['token'], $handle->token)) {
            return false;
        }

        $updated = json_encode([
            'token' => $handle->token,
            'expiresAt' => microtime(true) + (float) $ttlSeconds,
        ], JSON_THROW_ON_ERROR);

        ftruncate($fileHandle, 0);
        fseek($fileHandle, 0);
        fwrite($fileHandle, $updated);

        return true;
    }

    private function lockPath(string $resource): string
    {
        return $this->directory . '/' . hash('sha256', $resource) . '.lock';
    }

    /**
     * @param resource $handle
     * @return array{token: string, expiresAt: float}|null
     */
    private function readMetadata(mixed $handle): ?array
    {
        fseek($handle, 0, SEEK_END);
        $size = ftell($handle);

        if ($size === false || $size <= 0) {
            return null;
        }

        fseek($handle, 0);
        $contents = fread($handle, $size);

        if ($contents === false || $contents === '') {
            return null;
        }

        /** @var array{token: string, expiresAt: float}|null $data */
        $data = json_decode($contents, true);

        return $data;
    }
}
