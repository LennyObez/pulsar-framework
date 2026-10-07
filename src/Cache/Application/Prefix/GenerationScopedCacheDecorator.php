<?php

declare(strict_types=1);

namespace Pulsar\Cache\Application\Prefix;

use Pulsar\Api\Internal;
use Pulsar\Cache\Application\Driver\CacheDriverCapabilities;
use Pulsar\Cache\Application\Driver\CacheDriverInterface;
use Pulsar\Runtime\ResettableInterface;

use function array_map;
use function ctype_digit;
use function strlen;
use function substr;

/**
 * Prefix namespacing with generation-based clear, for backends that cannot
 * enumerate keys but do reclaim orphans by eviction (Memcached).
 *
 * Every data key is stored as `{prefix}g{generation}.{key}`, where the
 * generation is an integer counter at `{prefix}gen`. {@see clear()} atomically
 * increments that counter, so every live key instantly maps to a new,
 * never-written generation — a scoped clear without deleting or enumerating
 * anything. The previous generation's keys are simply never addressed again and
 * are pushed out by the backend's LRU. This is the Memcached idiom for
 * namespaced invalidation; it is selected only for drivers that promise
 * reclamation via {@see \Pulsar\Cache\Application\Driver\GenerationClearableInterface}.
 *
 * The generation counter is read and bumped through the RAW driver
 * (`$counter`), not the data stack (`$inner`): the counter is a plain integer
 * that must support atomic increment, and routing it around any encryption
 * decorator keeps clear() working on encrypted pools (where increment on the
 * data stack would be refused). Data still flows through the full stack, so the
 * generational key is what the encryption decorator binds into its AAD.
 *
 * The generation is memoized per request and reset via {@see ResettableInterface}
 * on persistent runtimes. A clear() by another worker is therefore observed at
 * the next request boundary — the same briefly-stale window the best-effort tag
 * strategy documents — while a clear() within this request updates the memo
 * immediately.
 */
#[Internal]
final class GenerationScopedCacheDecorator implements CacheDriverInterface, ResettableInterface
{
    private ?int $generation = null;

    /**
     * @param CacheDriverInterface $inner   Data stack (compression/encryption/driver).
     * @param CacheDriverInterface $counter Raw driver for the generation counter —
     *     must support atomic increment and bypass the data stack's encryption.
     * @param string $prefix Pool key prefix.
     */
    public function __construct(
        private readonly CacheDriverInterface $inner,
        private readonly CacheDriverInterface $counter,
        private readonly string $prefix,
    ) {}

    public function get(string $key): ?string
    {
        return $this->inner->get($this->dataKey($key));
    }

    /**
     * @param list<string> $keys
     *
     * @return array<string, string|null>
     */
    public function getMultiple(array $keys): array
    {
        $map = [];
        foreach ($keys as $key) {
            $map[$this->dataKey($key)] = $key;
        }

        $result = [];
        foreach ($this->inner->getMultiple(array_map(fn(string $k): string => $this->dataKey($k), $keys)) as $storageKey => $value) {
            $logical = $map[(string) $storageKey] ?? substr((string) $storageKey, strlen($this->dataPrefix()));
            $result[$logical] = $value;
        }

        return $result;
    }

    public function set(string $key, string $value, ?int $ttlSeconds): bool
    {
        return $this->inner->set($this->dataKey($key), $value, $ttlSeconds);
    }

    public function add(string $key, string $value, ?int $ttlSeconds): bool
    {
        return $this->inner->add($this->dataKey($key), $value, $ttlSeconds);
    }

    /**
     * @param array<string, string> $values
     */
    public function setMultiple(array $values, ?int $ttlSeconds): bool
    {
        $mapped = [];
        foreach ($values as $key => $value) {
            $mapped[$this->dataKey($key)] = $value;
        }

        return $this->inner->setMultiple($mapped, $ttlSeconds);
    }

    public function delete(string $key): bool
    {
        return $this->inner->delete($this->dataKey($key));
    }

    /**
     * @param list<string> $keys
     */
    public function deleteMultiple(array $keys): bool
    {
        return $this->inner->deleteMultiple(array_map(fn(string $k): string => $this->dataKey($k), $keys));
    }

    public function has(string $key): bool
    {
        return $this->inner->has($this->dataKey($key));
    }

    /**
     * Bump the generation counter: an atomic, enumeration-free prefix clear.
     */
    public function clear(): bool
    {
        $new = $this->counter->increment($this->generationKey(), 1);

        if ($new === false) {
            // The counter does not exist yet. Create it at 1 (atomic SETNX); if a
            // concurrent clear() created it first, our create fails and we bump.
            $new = $this->counter->add($this->generationKey(), '1', null)
                ? 1
                : $this->counter->increment($this->generationKey(), 1);
        }

        if ($new === false) {
            return false;
        }

        $this->generation = $new;

        return true;
    }

    public function increment(string $key, int $step = 1): int|false
    {
        return $this->inner->increment($this->dataKey($key), $step);
    }

    public function decrement(string $key, int $step = 1): int|false
    {
        return $this->inner->decrement($this->dataKey($key), $step);
    }

    public function capabilities(): CacheDriverCapabilities
    {
        return $this->inner->capabilities();
    }

    public function name(): string
    {
        return 'generation:' . $this->inner->name();
    }

    public function resetRequestState(): void
    {
        $this->generation = null;
    }

    private function generation(): int
    {
        if ($this->generation !== null) {
            return $this->generation;
        }

        $raw = $this->counter->get($this->generationKey());

        return $this->generation = ($raw !== null && ctype_digit($raw)) ? (int) $raw : 0;
    }

    private function generationKey(): string
    {
        // Distinct from any data key: data keys are "{prefix}g{digits}.{key}",
        // this is "{prefix}gen" (the char after 'g' is a letter, not a digit).
        return $this->prefix . 'gen';
    }

    private function dataPrefix(): string
    {
        return $this->prefix . 'g' . $this->generation() . '.';
    }

    private function dataKey(string $key): string
    {
        return $this->dataPrefix() . $key;
    }
}
