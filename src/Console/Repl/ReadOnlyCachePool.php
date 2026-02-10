<?php

declare(strict_types=1);

namespace Pulsar\Console\Repl;

use Override;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Pulsar\Api\Internal;

/**
 * Read-only PSR-6 cache pool decorator for REPL safe mode.
 *
 * Read operations delegate to the inner pool.
 * Write operations throw ReplSafeModeException.
 */
#[Internal]
final readonly class ReadOnlyCachePool implements CacheItemPoolInterface
{
    public function __construct(
        private CacheItemPoolInterface $inner,
    ) {}

    #[Override]
    public function getItem(string $key): CacheItemInterface
    {
        return $this->inner->getItem($key);
    }

    /**
     * @param array<array-key, string> $keys
     *
     * @return iterable<string, CacheItemInterface>
     */
    #[Override]
    public function getItems(array $keys = []): iterable
    {
        /** @var iterable<string, CacheItemInterface> */
        return $this->inner->getItems($keys);
    }

    #[Override]
    public function hasItem(string $key): bool
    {
        return $this->inner->hasItem($key);
    }

    /**
     * @throws ReplSafeModeException Always — blocked in safe mode
     */
    #[Override]
    public function clear(): bool
    {
        throw ReplSafeModeException::operationBlocked('cachePool:clear');
    }

    /**
     * @throws ReplSafeModeException Always — blocked in safe mode
     */
    #[Override]
    public function deleteItem(string $key): bool
    {
        throw ReplSafeModeException::operationBlocked('cachePool:deleteItem');
    }

    /**
     * @throws ReplSafeModeException Always — blocked in safe mode
     */
    #[Override]
    public function deleteItems(array $keys): bool
    {
        throw ReplSafeModeException::operationBlocked('cachePool:deleteItems');
    }

    /**
     * @throws ReplSafeModeException Always — blocked in safe mode
     */
    #[Override]
    public function save(CacheItemInterface $item): bool
    {
        throw ReplSafeModeException::operationBlocked('cachePool:save');
    }

    /**
     * @throws ReplSafeModeException Always — blocked in safe mode
     */
    #[Override]
    public function saveDeferred(CacheItemInterface $item): bool
    {
        throw ReplSafeModeException::operationBlocked('cachePool:saveDeferred');
    }

    /**
     * @throws ReplSafeModeException Always — blocked in safe mode
     */
    #[Override]
    public function commit(): bool
    {
        throw ReplSafeModeException::operationBlocked('cachePool:commit');
    }
}
