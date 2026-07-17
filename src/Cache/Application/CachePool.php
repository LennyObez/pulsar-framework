<?php

declare(strict_types=1);

namespace Pulsar\Cache\Application;

use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Pulsar\Api\Api;
use Pulsar\Cache\Application\Driver\CacheDriverInterface;
use Pulsar\Cache\Application\Event\CacheEventEmitter;
use Pulsar\Cache\Application\Exception\CacheException;
use Pulsar\Cache\Application\Lock\LockInterface;
use Pulsar\Cache\Application\Serializer\CacheSerializerInterface;
use Random\Engine\Secure;
use Random\Randomizer;
use Throwable;

use function array_values;
use function hrtime;
use function time;
use function usleep;

/**
 * PSR-6 CacheItemPoolInterface implementation.
 * @api
 */
#[Api(since: '1.0.0')]
final class CachePool implements CacheItemPoolInterface
{
    /**
     * Prefix for the per-key stampede lock resource, kept distinct from the
     * cached key so the lock never collides with a real cache entry. Lock
     * resources bypass CacheKeyValidator, but the dot separator keeps every
     * cache-layer identifier within the same PSR-6-safe grammar.
     */
    private const string STAMPEDE_LOCK_PREFIX = '_stampede.';

    /**
     * After a stampede lock wait times out, poll the cache for the winner's
     * write for up to this long (in ms) before falling back to an unlocked
     * compute. Without this window every loser that times out at the same
     * instant would recompute at once — the very herd the lock prevents — when
     * regeneration outlasts the lock timeout. Bounded so a request never hangs
     * on a dead winner; the primary tuning lever is the per-pool lock timeout.
     */
    private const int LOSER_POLL_WINDOW_MS = 250;

    /** Interval between winner-write polls during {@see LOSER_POLL_WINDOW_MS}. */
    private const int LOSER_POLL_INTERVAL_MS = 25;

    /** @var array<string, CacheItem> */
    private array $deferred = [];

    private readonly Randomizer $randomizer;

    /**
     * @param ?LockInterface $stampedeLock When set, {@see remember()} guards
     *     regeneration with a per-key lock so a key expiring under load is
     *     recomputed by a single caller (the others wait, then read the value
     *     the winner wrote) instead of every concurrent request stampeding the
     *     backend. Null disables the guard — remember() is then a plain
     *     get-or-compute, preserving the historical single-process behaviour.
     */
    public function __construct(
        private readonly string $poolName,
        private readonly CacheDriverInterface $driver,
        private readonly CacheSerializerInterface $serializer,
        private readonly CacheEventEmitter $eventEmitter,
        private readonly ?int $defaultTtlSeconds = null,
        private readonly bool $critical = false,
        private readonly ?LockInterface $stampedeLock = null,
        private readonly int $stampedeLockTtlSeconds = 30,
        private readonly int $stampedeLockTimeoutMs = 5000,
        private readonly float $stampedeJitterFactor = 0.1,
        ?Randomizer $randomizer = null,
    ) {
        $this->randomizer = $randomizer ?? new Randomizer(new Secure());
    }

    /**
     * @throws CacheException On driver failure in critical mode
     */
    public function getItem(string $key): CacheItemInterface
    {
        CacheKeyValidator::validate($key);
        $start = hrtime(true);

        // Check deferred items first
        if (isset($this->deferred[$key])) {
            $this->eventEmitter->emitHit($this->poolName, $this->driver->name(), $key, $start);
            return clone $this->deferred[$key];
        }

        try {
            $raw = $this->driver->get($key);

            if ($raw === null) {
                $this->eventEmitter->emitMiss($this->poolName, $this->driver->name(), $key, $start);
                return CacheItem::miss($key);
            }

            /** @var mixed $value */
            $value = $this->serializer->deserialize($raw);
            $this->eventEmitter->emitHit($this->poolName, $this->driver->name(), $key, $start);

            return CacheItem::hit($key, $value);
        } catch (CacheException $e) {
            throw $e;
        } catch (Throwable $e) {
            return $this->handleGetError($key, $start, $e);
        }
    }

    /**
     * @param array<string> $keys
     *
     * @return iterable<string, CacheItemInterface>
     *
     * @throws CacheException On driver failure in critical mode
     */
    public function getItems(array $keys = []): iterable
    {
        if ($keys === []) {
            return [];
        }

        CacheKeyValidator::validateMultiple($keys);
        $start = hrtime(true);

        // Separate deferred keys from keys that need a driver round-trip
        $results = [];
        $driverKeys = [];

        foreach ($keys as $key) {
            if (isset($this->deferred[$key])) {
                $results[$key] = clone $this->deferred[$key];
                $this->eventEmitter->emitHit($this->poolName, $this->driver->name(), $key, $start);
            } else {
                $driverKeys[] = $key;
            }
        }

        if ($driverKeys !== []) {
            try {
                $rawValues = $this->driver->getMultiple($driverKeys);

                foreach ($driverKeys as $key) {
                    $raw = $rawValues[$key] ?? null;

                    if ($raw === null) {
                        $results[$key] = CacheItem::miss($key);
                        $this->eventEmitter->emitMiss($this->poolName, $this->driver->name(), $key, $start);
                    } else {
                        /** @var mixed $value */
                        $value = $this->serializer->deserialize($raw);
                        $results[$key] = CacheItem::hit($key, $value);
                        $this->eventEmitter->emitHit($this->poolName, $this->driver->name(), $key, $start);
                    }
                }
            } catch (CacheException $e) {
                throw $e;
            } catch (Throwable $e) {
                foreach ($driverKeys as $key) {
                    if (!isset($results[$key])) {
                        $results[$key] = $this->handleGetError($key, $start, $e);
                    }
                }
            }
        }

        // Return in the original key order
        $ordered = [];

        foreach ($keys as $key) {
            $ordered[$key] = $results[$key];
        }

        return $ordered;
    }

    public function hasItem(string $key): bool
    {
        CacheKeyValidator::validate($key);
        $start = hrtime(true);

        if (isset($this->deferred[$key])) {
            $this->eventEmitter->emitHit($this->poolName, $this->driver->name(), $key, $start);
            return true;
        }

        try {
            $exists = $this->driver->has($key);

            if ($exists) {
                $this->eventEmitter->emitHit($this->poolName, $this->driver->name(), $key, $start);
            } else {
                $this->eventEmitter->emitMiss($this->poolName, $this->driver->name(), $key, $start);
            }

            return $exists;
        } catch (Throwable) {
            $this->eventEmitter->emitMiss($this->poolName, $this->driver->name(), $key, $start);
            return false;
        }
    }

    /**
     * @throws CacheException On driver failure in critical mode
     */
    public function clear(): bool
    {
        $this->deferred = [];
        $start = hrtime(true);

        try {
            $result = $this->driver->clear();
            $this->eventEmitter->emitClear($this->poolName, $this->driver->name(), $start);

            return $result;
        } catch (Throwable $e) {
            if ($this->critical) {
                throw CacheException::driverError($this->driver->name(), $e->getMessage(), $e);
            }
            return false;
        }
    }

    /**
     * @throws CacheException On driver failure in critical mode
     */
    public function deleteItem(string $key): bool
    {
        CacheKeyValidator::validate($key);
        $start = hrtime(true);

        unset($this->deferred[$key]);

        try {
            $result = $this->driver->delete($key);
            $this->eventEmitter->emitDelete($this->poolName, $this->driver->name(), $key, $start);

            return $result;
        } catch (Throwable $e) {
            $this->handleWriteError($key, $start, $e);
            return false;
        }
    }

    /**
     * @param array<string> $keys
     *
     * @throws CacheException On driver failure in critical mode
     */
    public function deleteItems(array $keys): bool
    {
        if ($keys === []) {
            return true;
        }

        CacheKeyValidator::validateMultiple($keys);
        $start = hrtime(true);

        foreach ($keys as $key) {
            unset($this->deferred[$key]);
        }

        try {
            $result = $this->driver->deleteMultiple(array_values($keys));

            foreach ($keys as $key) {
                $this->eventEmitter->emitDelete($this->poolName, $this->driver->name(), $key, $start);
            }

            return $result;
        } catch (Throwable $e) {
            foreach ($keys as $key) {
                $this->handleWriteError($key, $start, $e);
            }
            return false;
        }
    }

    /**
     * @throws CacheException On driver failure in critical mode
     */
    public function save(CacheItemInterface $item): bool
    {
        return $this->saveItem($item);
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        if (!$item instanceof CacheItem) {
            return false;
        }

        $this->deferred[$item->getKey()] = $item;

        return true;
    }

    public function commit(): bool
    {
        $success = true;

        foreach ($this->deferred as $key => $item) {
            if (!$this->saveItem($item)) {
                $success = false;
            }
            unset($this->deferred[$key]);
        }

        return $success;
    }

    /**
     * Get-or-compute with built-in stampede protection.
     *
     * On a hit the cached value is returned. On a miss, when the pool was
     * constructed with a stampede lock, a single caller acquires a per-key lock
     * and regenerates while concurrent callers wait and then read the value the
     * winner wrote (double-checked) — preventing a thundering herd from all
     * recomputing an expensive value at once. If the lock cannot be acquired in
     * time the request falls back to computing directly rather than failing. The
     * regenerated entry's TTL is jittered so keys written together do not all
     * expire on the same tick and re-stampede.
     *
     * All reads and writes go through {@see getItem()}/{@see save()}, so hit,
     * miss, write and error events are emitted and critical-mode failures still
     * surface as they do for every other pool operation.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     *
     * @throws CacheException On driver failure in critical mode
     */
    public function remember(string $key, callable $callback, ?int $ttlSeconds = null): mixed
    {
        $item = $this->getItem($key);

        if ($item->isHit()) {
            /** @var T */
            return $item->get();
        }

        if ($this->stampedeLock === null) {
            /** @var T */
            return $this->computeAndSave($key, $callback, $ttlSeconds ?? $this->defaultTtlSeconds);
        }

        try {
            $handle = $this->stampedeLock->acquire(
                self::STAMPEDE_LOCK_PREFIX . $key,
                $this->stampedeLockTtlSeconds,
                $this->stampedeLockTimeoutMs,
            );
        } catch (Throwable) {
            // Lock unavailable or timed out: a winner is likely still
            // regenerating. Poll briefly for its write before falling back to
            // an unlocked compute, so N losers timing out together do not all
            // stampede the backend the instant the lock times out.
            /** @var T */
            return $this->awaitWinnerOrCompute($key, $callback, $ttlSeconds ?? $this->defaultTtlSeconds);
        }

        try {
            // The winner may have written while we waited for the lock.
            $doubleCheck = $this->getItem($key);

            if ($doubleCheck->isHit()) {
                /** @var T */
                return $doubleCheck->get();
            }

            /** @var T */
            return $this->computeAndSave(
                $key,
                $callback,
                $this->applyJitter($ttlSeconds ?? $this->defaultTtlSeconds),
            );
        } finally {
            $this->stampedeLock->release($handle);
        }
    }

    /**
     * Invoke the callback and persist its result under the given resolved TTL.
     *
     * @template T
     * @param callable(): T $callback
     * @param ?int $resolvedTtlSeconds Already-resolved TTL (null = no expiry);
     *     not re-resolved against the pool default here.
     * @return T
     *
     * @throws CacheException On driver failure in critical mode
     */
    private function computeAndSave(string $key, callable $callback, ?int $resolvedTtlSeconds): mixed
    {
        /** @var T $value */
        $value = $callback();

        $cacheItem = CacheItem::miss($key);
        $cacheItem->set($value);

        if ($resolvedTtlSeconds !== null) {
            $cacheItem->expiresAfter($resolvedTtlSeconds);
        }

        $this->save($cacheItem);

        return $value;
    }

    /**
     * Poll for the stampede winner's write within a bounded window, then
     * fail-open by computing directly. Splits a herd of losers that all time
     * out at once: most pick up the winner's value during the poll; only a
     * dead or extremely slow winner forces a fallback compute.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     *
     * @throws CacheException On driver failure in critical mode
     */
    private function awaitWinnerOrCompute(string $key, callable $callback, ?int $resolvedTtlSeconds): mixed
    {
        $deadlineNs = hrtime(true) + self::LOSER_POLL_WINDOW_MS * 1_000_000;

        do {
            $retry = $this->getItem($key);

            if ($retry->isHit()) {
                /** @var T */
                return $retry->get();
            }

            $this->cooperativeSleepMs(self::LOSER_POLL_INTERVAL_MS);
        } while (hrtime(true) < $deadlineNs);

        /** @var T */
        return $this->computeAndSave($key, $callback, $resolvedTtlSeconds);
    }

    /**
     * Sleep for the given milliseconds. Currently a plain usleep; on the
     * persistent runtime this blocks the worker, which issue #418 replaces
     * with a fiber-aware yield. Kept as the single sleep site so that change
     * lands in one place.
     */
    private function cooperativeSleepMs(int $milliseconds): void
    {
        usleep($milliseconds * 1000);
    }

    /**
     * Subtract a random slice (up to jitterFactor of the TTL) so entries written
     * together under a lock do not all expire on the same tick and re-stampede.
     */
    private function applyJitter(?int $ttlSeconds): ?int
    {
        if ($ttlSeconds === null || $ttlSeconds <= 0) {
            return $ttlSeconds;
        }

        $jitter = (int) ((float) $ttlSeconds * $this->stampedeJitterFactor);

        if ($jitter <= 0) {
            return $ttlSeconds;
        }

        return $ttlSeconds - $this->randomizer->getInt(0, $jitter);
    }

    /**
     * @throws CacheException On driver failure in critical mode
     */
    private function saveItem(CacheItemInterface $item): bool
    {
        if (!$item instanceof CacheItem) {
            return false;
        }

        $key = $item->getKey();
        CacheKeyValidator::validate($key);
        $start = hrtime(true);

        try {
            $serialized = $this->serializer->serialize($item->get());
            $ttl = $this->resolveItemTtl($item);
            $result = $this->driver->set($key, $serialized, $ttl);

            $this->eventEmitter->emitWrite($this->poolName, $this->driver->name(), $key, $start);

            return $result;
        } catch (CacheException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->handleWriteError($key, $start, $e);
            return false;
        }
    }

    private function resolveItemTtl(CacheItem $item): ?int
    {
        $expiration = $item->expiration;

        if ($expiration !== null) {
            $diff = $expiration->getTimestamp() - time();

            return max(0, $diff);
        }

        return $this->defaultTtlSeconds;
    }

    private function handleGetError(string $key, int $startNs, Throwable $e): CacheItem
    {
        $this->eventEmitter->emitError(
            $this->poolName,
            $this->driver->name(),
            $key,
            $startNs,
            $e->getMessage(),
            $e,
        );

        if ($this->critical) {
            throw CacheException::driverError($this->driver->name(), $e->getMessage(), $e);
        }

        return CacheItem::miss($key);
    }

    private function handleWriteError(string $key, int $startNs, Throwable $e): void
    {
        $this->eventEmitter->emitError(
            $this->poolName,
            $this->driver->name(),
            $key,
            $startNs,
            $e->getMessage(),
            $e,
        );

        if ($this->critical) {
            throw CacheException::driverError($this->driver->name(), $e->getMessage(), $e);
        }
    }
}
