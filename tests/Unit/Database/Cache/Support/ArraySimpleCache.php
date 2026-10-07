<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Cache\Support;

use DateInterval;
use Psr\SimpleCache\CacheInterface;

use function array_key_exists;
use function is_int;
use function str_starts_with;

/**
 * A PSR-16 cache that keeps everything in an array and remembers the TTLs it was
 * given, so a test can assert which lifetime a setting actually produced.
 *
 * Expiry is not simulated: nothing here needs it, and a clock in a test double is a
 * second thing that can be wrong.
 */
final class ArraySimpleCache implements CacheInterface
{
    /** @var array<string, mixed> */
    private array $values = [];

    /** @var list<int> TTLs passed for query-result entries, in call order. */
    private array $ttls = [];

    /**
     * Cached query results, excluding the tag-version counters {@see \Pulsar\Database\Cache\QueryCache}
     * keeps alongside them: a test asking "was anything cached?" means results.
     *
     * @return list<mixed>
     */
    public function entries(): array
    {
        $entries = [];

        foreach ($this->values as $key => $value) {
            if (!str_starts_with($key, 'qc_tagver.')) {
                $entries[] = $value;
            }
        }

        return $entries;
    }

    /**
     * @return list<int>
     */
    public function ttls(): array
    {
        return $this->ttls;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->values) ? $this->values[$key] : $default;
    }

    public function set(string $key, mixed $value, DateInterval|int|null $ttl = null): bool
    {
        $this->values[$key] = $value;

        if (is_int($ttl) && !str_starts_with($key, 'qc_tagver.')) {
            $this->ttls[] = $ttl;
        }

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->values[$key]);

        return true;
    }

    public function clear(): bool
    {
        $this->values = [];
        $this->ttls = [];

        return true;
    }

    /**
     * @param iterable<string> $keys
     * @return iterable<string, mixed>
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $found = [];

        foreach ($keys as $key) {
            $found[$key] = $this->get($key, $default);
        }

        return $found;
    }

    /**
     * @param iterable<string, mixed> $values
     */
    public function setMultiple(iterable $values, DateInterval|int|null $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value, $ttl);
        }

        return true;
    }

    /**
     * @param iterable<string> $keys
     */
    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }

        return true;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }
}
