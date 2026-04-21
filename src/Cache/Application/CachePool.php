<?php

declare(strict_types=1);

namespace Pulsar\Cache\Application;

use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Pulsar\Api\Api;
use Pulsar\Cache\Application\Driver\CacheDriverInterface;
use Pulsar\Cache\Application\Event\CacheEventEmitter;
use Pulsar\Cache\Application\Exception\CacheException;
use Pulsar\Cache\Application\Serializer\CacheSerializerInterface;
use Throwable;

use function array_values;
use function hrtime;
use function time;

/**
 * PSR-6 CacheItemPoolInterface implementation.
 * @api
 */
#[Api(since: '1.0.0')]
final class CachePool implements CacheItemPoolInterface
{
    /** @var array<string, CacheItem> */
    private array $deferred = [];

    public function __construct(
        private readonly string $poolName,
        private readonly CacheDriverInterface $driver,
        private readonly CacheSerializerInterface $serializer,
        private readonly CacheEventEmitter $eventEmitter,
        private readonly ?int $defaultTtlSeconds = null,
        private readonly bool $critical = false,
    ) {}

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
     * Convenience method: get-or-compute with stampede protection integration point.
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

        /** @var T $value */
        $value = $callback();

        $cacheItem = CacheItem::miss($key);
        $cacheItem->set($value);

        $ttl = $ttlSeconds ?? $this->defaultTtlSeconds;
        if ($ttl !== null) {
            $cacheItem->expiresAfter($ttl);
        }

        $this->save($cacheItem);

        return $value;
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
