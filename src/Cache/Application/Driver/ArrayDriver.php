<?php

declare(strict_types=1);

namespace Pulsar\Cache\Application\Driver;

use Pulsar\Api\Internal;

use function array_key_exists;
use function time;

/**
 * In-memory array cache driver.
 *
 * Stores values in a plain PHP array. Useful for testing and
 * single-request caching where persistence is not required.
 */
#[Internal]
final class ArrayDriver extends AbstractCacheDriver
{
    /** @var array<string, array{value: string, expiresAt: ?int}> */
    private array $store = [];

    public function get(string $key): ?string
    {
        if (!array_key_exists($key, $this->store)) {
            return null;
        }

        $entry = $this->store[$key];

        if ($entry['expiresAt'] !== null && $entry['expiresAt'] <= time()) {
            unset($this->store[$key]);

            return null;
        }

        return $entry['value'];
    }

    public function set(string $key, string $value, ?int $ttlSeconds): bool
    {
        $ttl = $this->normalizeTtl($ttlSeconds);

        if ($this->isExpiredTtl($ttl)) {
            $this->delete($key);

            return true;
        }

        $this->store[$key] = [
            'value' => $value,
            'expiresAt' => $ttl !== null ? time() + $ttl : null,
        ];

        return true;
    }
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */

    public function delete(string $key): bool
    {
        unset($this->store[$key]);

        return true;
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    public function clear(): bool
    {
        $this->store = [];

        return true;
    }

    public function increment(string $key, int $step = 1): int
    {
        $current = $this->get($key);
        $value = ($current !== null ? (int) $current : 0) + $step;
        $expiresAt = array_key_exists($key, $this->store) ? $this->store[$key]['expiresAt'] : null;

        $this->store[$key] = [
            'value' => (string) $value,
            'expiresAt' => $expiresAt,
        ];

        return $value;
    }

    public function decrement(string $key, int $step = 1): int
    {
        return $this->increment($key, -$step);
    }

    public function capabilities(): CacheDriverCapabilities
    {
        return new CacheDriverCapabilities(
            supportsBinary: true,
            supportsAtomicIncrement: true,
        );
    }

    public function name(): string
    {
        return 'array';
    }
}
