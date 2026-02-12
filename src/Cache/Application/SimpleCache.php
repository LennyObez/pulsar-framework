<?php

declare(strict_types=1);

namespace Pulsar\Cache\Application;

use DateInterval;
use Psr\Cache\CacheItemInterface;
use Psr\SimpleCache\CacheInterface;
use Pulsar\Api\Api;

/**
 * PSR-16 CacheInterface implementation wrapping a PSR-6 CachePool.
 */
#[Api(since: '1.0.0')]
final readonly class SimpleCache implements CacheInterface
{
    public function __construct(
        private readonly CachePool $pool,
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        $item = $this->pool->getItem($key);

        return $item->isHit() ? $item->get() : $default;
    }

    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        $item = new CacheItem($key);
        $item->set($value);

        if ($ttl !== null) {
            $item->expiresAfter($ttl);
        }

        return $this->pool->save($item);
    }

    public function delete(string $key): bool
    {
        return $this->pool->deleteItem($key);
    }

    public function clear(): bool
    {
        return $this->pool->clear();
    }

    /**
     * @param iterable<string> $keys
     *
     * @return iterable<string, mixed>
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $keyList = $this->iterableToArray($keys);
        $items = $this->pool->getItems($keyList);
        $result = [];

        /** @var CacheItemInterface $item */
        foreach ($items as $key => $item) {
            $result[$key] = $item->isHit() ? $item->get() : $default;
        }

        return $result;
    }

    /** @param iterable<mixed, mixed> $values */
    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        $success = true;

        /** @var string $key */
        foreach ($values as $key => $value) {
            $item = new CacheItem($key);
            $item->set($value);

            if ($ttl !== null) {
                $item->expiresAfter($ttl);
            }

            if (!$this->pool->save($item)) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * @param iterable<string> $keys
     */
    public function deleteMultiple(iterable $keys): bool
    {
        return $this->pool->deleteItems($this->iterableToArray($keys));
    }

    public function has(string $key): bool
    {
        return $this->pool->hasItem($key);
    }

    /**
     * Convenience method: get-or-compute.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function remember(string $key, callable $callback, null|int|DateInterval $ttl = null): mixed
    {
        $item = $this->pool->getItem($key);

        if ($item->isHit()) {
            /** @var T */
            return $item->get();
        }

        /** @var T $value */
        $value = $callback();
        $this->set($key, $value, $ttl);

        return $value;
    }

    /**
     * @param iterable<string> $iterable
     * @return list<string>
     */
    private function iterableToArray(iterable $iterable): array
    {
        $result = [];

        foreach ($iterable as $value) {
            $result[] = $value;
        }

        return $result;
    }
}
