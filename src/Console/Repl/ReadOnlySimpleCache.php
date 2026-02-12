<?php

declare(strict_types=1);

namespace Pulsar\Console\Repl;

use DateInterval;
use Override;
use Psr\SimpleCache\CacheInterface;
use Pulsar\Api\Internal;

/**
 * Read-only PSR-16 simple cache decorator for REPL safe mode.
 *
 * Read operations delegate to the inner cache.
 * Write operations throw ReplSafeModeException.
 */
#[Internal]
final readonly class ReadOnlySimpleCache implements CacheInterface
{
    public function __construct(
        private CacheInterface $inner,
    ) {}

    #[Override]
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->inner->get($key, $default);
    }

    /**
     * @throws ReplSafeModeException Always — blocked in safe mode
     */
    #[Override]
    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        throw ReplSafeModeException::operationBlocked('cache:set');
    }

    /**
     * @throws ReplSafeModeException Always — blocked in safe mode
     */
    #[Override]
    public function delete(string $key): bool
    {
        throw ReplSafeModeException::operationBlocked('cache:delete');
    }

    /**
     * @throws ReplSafeModeException Always — blocked in safe mode
     */
    #[Override]
    public function clear(): bool
    {
        throw ReplSafeModeException::operationBlocked('cache:clear');
    }

    #[Override]
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        return $this->inner->getMultiple($keys, $default);
    }

    /**
     * @param iterable<mixed, mixed> $values
     *
     * @throws ReplSafeModeException Always — blocked in safe mode
     */
    #[Override]
    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        throw ReplSafeModeException::operationBlocked('cache:setMultiple');
    }

    /**
     * @throws ReplSafeModeException Always — blocked in safe mode
     */
    #[Override]
    public function deleteMultiple(iterable $keys): bool
    {
        throw ReplSafeModeException::operationBlocked('cache:deleteMultiple');
    }

    #[Override]
    public function has(string $key): bool
    {
        return $this->inner->has($key);
    }
}
