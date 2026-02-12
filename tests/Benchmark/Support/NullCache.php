<?php

declare(strict_types=1);

namespace Pulsar\Tests\Benchmark\Support;

/**
 * No-op cache for Tier A benchmarks.
 *
 * All reads miss, all writes succeed silently.
 */
final class NullCache
{
    public function get(string $key, mixed $default = null): mixed
    {
        return $default;
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        return true;
    }

    public function has(string $key): bool
    {
        return false;
    }

    public function delete(string $key): bool
    {
        return true;
    }
}
