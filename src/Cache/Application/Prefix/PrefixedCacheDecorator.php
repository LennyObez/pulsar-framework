<?php

declare(strict_types=1);

namespace Pulsar\Cache\Application\Prefix;

use Pulsar\Api\Internal;
use Pulsar\Cache\Application\Driver\CacheDriverCapabilities;
use Pulsar\Cache\Application\Driver\CacheDriverInterface;
use Pulsar\Cache\Application\Driver\PrefixClearableInterface;
use Pulsar\Cache\Application\Exception\UnsupportedCapabilityException;

use function array_map;
use function strlen;
use function substr;

/**
 * Per-pool key namespacing for shared backends (opt-in via the pool `prefix`).
 *
 * Redis and Memcached store keys raw and memoize connections per host:port, so
 * two pools — or two applications — on one backend share a single keyspace:
 * keys can collide, and clear() (FLUSHDB / flush) wipes everything on the
 * shared database or server. Prefixing every key isolates the namespaces, and
 * clear() becomes an exact prefix-scoped deletion on drivers that can
 * enumerate keys ({@see PrefixClearableInterface}); on drivers that cannot, a
 * prefixed clear() throws instead of silently flushing beyond its pool.
 *
 * This decorator is OUTERMOST in the stack (caller → Prefix → Compression →
 * Encryption → driver), which is load-bearing: the encryption decorator binds
 * the key it receives into the AAD, so with the prefix applied first the
 * ciphertext is bound to the FINAL storage key — a ciphertext written under
 * one prefix cannot be transplanted to the same logical key under another.
 *
 * Deliberate non-coverage, documented in ADR-0018: lock resources
 * (CacheManager::lock(), the stampede lock) resolve from raw connections below
 * this decorator and are NOT prefixed — prefix isolation applies to data keys
 * only.
 */
#[Internal]
final readonly class PrefixedCacheDecorator implements CacheDriverInterface
{
    public function __construct(
        private CacheDriverInterface $inner,
        private string $prefix,
    ) {}

    public function get(string $key): ?string
    {
        return $this->inner->get($this->prefix . $key);
    }

    /**
     * @param list<string> $keys
     *
     * @return array<string, string|null>
     */
    public function getMultiple(array $keys): array
    {
        $prefixed = array_map(fn(string $key): string => $this->prefix . $key, $keys);

        $result = [];

        foreach ($this->inner->getMultiple($prefixed) as $storageKey => $value) {
            $result[substr((string) $storageKey, strlen($this->prefix))] = $value;
        }

        return $result;
    }

    public function set(string $key, string $value, ?int $ttlSeconds): bool
    {
        return $this->inner->set($this->prefix . $key, $value, $ttlSeconds);
    }

    public function add(string $key, string $value, ?int $ttlSeconds): bool
    {
        return $this->inner->add($this->prefix . $key, $value, $ttlSeconds);
    }

    /**
     * @param array<string, string> $values
     */
    public function setMultiple(array $values, ?int $ttlSeconds): bool
    {
        $prefixed = [];

        foreach ($values as $key => $value) {
            $prefixed[$this->prefix . $key] = $value;
        }

        return $this->inner->setMultiple($prefixed, $ttlSeconds);
    }

    public function delete(string $key): bool
    {
        return $this->inner->delete($this->prefix . $key);
    }

    /**
     * @param list<string> $keys
     */
    public function deleteMultiple(array $keys): bool
    {
        return $this->inner->deleteMultiple(
            array_map(fn(string $key): string => $this->prefix . $key, $keys),
        );
    }

    public function has(string $key): bool
    {
        return $this->inner->has($this->prefix . $key);
    }

    /**
     * Prefix-scoped clear: deletes exactly this pool's keys.
     *
     * @throws UnsupportedCapabilityException When the inner driver cannot
     *     enumerate keys — failing loudly beats silently flushing a backend
     *     shared with other pools and applications.
     */
    public function clear(): bool
    {
        if ($this->inner instanceof PrefixClearableInterface) {
            return $this->inner->clearByPrefix($this->prefix);
        }

        throw UnsupportedCapabilityException::prefixScopedClearUnsupported($this->inner->name());
    }

    public function increment(string $key, int $step = 1): int|false
    {
        return $this->inner->increment($this->prefix . $key, $step);
    }

    public function decrement(string $key, int $step = 1): int|false
    {
        return $this->inner->decrement($this->prefix . $key, $step);
    }

    public function capabilities(): CacheDriverCapabilities
    {
        return $this->inner->capabilities();
    }

    public function name(): string
    {
        return 'prefixed:' . $this->inner->name();
    }
}
