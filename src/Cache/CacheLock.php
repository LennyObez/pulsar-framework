<?php

declare(strict_types=1);

namespace Pulsar\Cache;

use const DIRECTORY_SEPARATOR;

use function dirname;
use function fclose;
use function flock;
use function fopen;
use function is_dir;

use const LOCK_EX;
use const LOCK_UN;

use function mkdir;

use Pulsar\Api\Internal;

/**
 * flock()-based write lock for concurrent optimize safety.
 *
 * Multiple concurrent `optimize` runs serialize cleanly.
 * Boot reads do not acquire locks (reads are safe against atomic writes).
 */
#[Internal]
final class CacheLock
{
    private const string LOCK_FILE = '.lock';

    /** @var resource|null */
    private mixed $handle = null;

    public function __construct(
        private readonly string $cacheDir,
    ) {}

    /**
     * Acquire an exclusive lock for cache writing.
     *
     * @throws CacheException If the lock cannot be acquired
     */
    public function acquire(): void
    {
        $lockPath = $this->cacheDir . DIRECTORY_SEPARATOR . self::LOCK_FILE;
        $dir = dirname($lockPath);

        if (!is_dir($dir) && !mkdir($dir, 0o750, true) && !is_dir($dir)) {
            throw CacheException::lockFailed($lockPath);
        }

        $handle = fopen($lockPath, 'cb');

        if ($handle === false) {
            throw CacheException::lockFailed($lockPath);
        }

        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            throw CacheException::lockFailed($lockPath);
        }

        $this->handle = $handle;
    }

    /**
     * Release the lock.
     */
    public function release(): void
    {
        if ($this->handle !== null) {
            /** @var resource $handle */
            $handle = $this->handle;
            flock($handle, LOCK_UN);
            fclose($handle);
            $this->handle = null;
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
