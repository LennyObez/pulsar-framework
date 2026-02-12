<?php

declare(strict_types=1);

namespace Pulsar\Cache\Application\Stampede;

use Pulsar\Api\Internal;
use Pulsar\Cache\Application\Driver\CacheDriverInterface;
use Pulsar\Cache\Application\Lock\LockInterface;
use Pulsar\Cache\Application\Serializer\CacheSerializerInterface;
use Random\Engine\Secure;
use Random\Randomizer;
use Throwable;

/**
 * Stampede protection for cache regeneration.
 *
 * Algorithm:
 * 1. Try get from driver
 * 2. On miss: acquire lock → invoke callback → set with jitter → release
 * 3. On lock timeout: retry get (another process may have regenerated), fallback to callback
 */
#[Internal]
final readonly class StampedeGuard
{
    private readonly Randomizer $randomizer;

    public function __construct(
        private readonly CacheDriverInterface $driver,
        private readonly CacheSerializerInterface $serializer,
        private readonly LockInterface $lock,
        private readonly int $lockTtlSeconds = 30,
        private readonly int $lockTimeoutMs = 5000,
        private readonly float $jitterFactor = 0.1,
    ) {
        $this->randomizer = new Randomizer(new Secure());
    }

    /**
     * Get-or-compute with stampede protection.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function remember(string $key, callable $callback, ?int $ttlSeconds = null): mixed
    {
        // Try cache first
        $raw = $this->driver->get($key);

        if ($raw !== null) {
            /** @var T */
            return $this->serializer->deserialize($raw);
        }

        // Attempt lock acquisition
        $lockResource = '_stampede:' . $key;

        try {
            $handle = $this->lock->acquire($lockResource, $this->lockTtlSeconds, $this->lockTimeoutMs);
        } catch (Throwable) {
            // Lock timeout — retry get, then fallback to callback
            $raw = $this->driver->get($key);

            if ($raw !== null) {
                /** @var T */
                return $this->serializer->deserialize($raw);
            }

            /** @var T */
            return $callback();
        }

        try {
            // Double-check after acquiring lock (another process may have written)
            $raw = $this->driver->get($key);

            if ($raw !== null) {
                /** @var T */
                return $this->serializer->deserialize($raw);
            }

            /** @var T $value */
            $value = $callback();
            $serialized = $this->serializer->serialize($value);

            $actualTtl = $this->applyJitter($ttlSeconds);
            $this->driver->set($key, $serialized, $actualTtl);

            return $value;
        } finally {
            $this->lock->release($handle);
        }
    }

    private function applyJitter(?int $ttlSeconds): ?int
    {
        if ($ttlSeconds === null || $ttlSeconds <= 0) {
            return $ttlSeconds;
        }

        $jitter = (int) ((float) $ttlSeconds * $this->jitterFactor);

        if ($jitter <= 0) {
            return $ttlSeconds;
        }

        return $ttlSeconds - $this->randomizer->getInt(0, $jitter);
    }
}
