<?php

declare(strict_types=1);

namespace Pulsar\Testing\Fake;

use PHPUnit\Framework\Assert;
use Pulsar\Api\Api;
use Pulsar\Cache\Application\Driver\CacheDriverCapabilities;
use Pulsar\Cache\Application\Driver\CacheDriverInterface;

use function array_filter;
use function array_key_exists;
use function array_keys;
use function count;
use function implode;
use function sprintf;

/**
 * Fake cache driver for testing: in-memory store with operation tracking.
 *
 * Records all get/set/delete operations for assertion, enabling tests to
 * verify caching behavior without a real cache backend.
 * @api
 */
#[Api(since: '1.0.0')]
final class CacheFake implements CacheDriverInterface
{
    /** @var array<string, string> */
    private array $store = [];

    /** @var list<array{operation: string, key: string, value?: string}> */
    private array $operations = [];

    public function get(string $key): ?string
    {
        $this->operations[] = ['operation' => 'get', 'key' => $key];

        return $this->store[$key] ?? null;
    }

    /**
     * @param list<string> $keys
     * @return array<string, string|null>
     */
    public function getMultiple(array $keys): array
    {
        $result = [];

        foreach ($keys as $key) {
            $result[$key] = $this->get($key);
        }

        return $result;
    }

    public function set(string $key, string $value, ?int $ttlSeconds): bool
    {
        if ($ttlSeconds !== null && $ttlSeconds <= 0) {
            $this->delete($key);

            return true;
        }

        $this->operations[] = ['operation' => 'set', 'key' => $key, 'value' => $value];
        $this->store[$key] = $value;

        return true;
    }

    public function setMultiple(array $values, ?int $ttlSeconds): bool
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value, $ttlSeconds);
        }

        return true;
    }

    public function delete(string $key): bool
    {
        $this->operations[] = ['operation' => 'delete', 'key' => $key];
        unset($this->store[$key]);

        return true;
    }

    public function deleteMultiple(array $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }

        return true;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->store);
    }

    public function clear(): bool
    {
        $this->operations[] = ['operation' => 'clear', 'key' => '*'];
        $this->store = [];

        return true;
    }

    public function increment(string $key, int $step = 1): int
    {
        $current = isset($this->store[$key]) ? (int) $this->store[$key] : 0;
        $value = $current + $step;
        $this->store[$key] = (string) $value;
        $this->operations[] = ['operation' => 'increment', 'key' => $key];

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
        return 'fake';
    }

    /**
     * Assert that a key exists in the cache.
     */
    public function assertHas(string $key): void
    {
        Assert::assertTrue(
            $this->has($key),
            sprintf(
                "Expected cache to contain key [%s], but it does not.\nKeys in cache: %s",
                $key,
                $this->formatKeys(),
            ),
        );
    }

    /**
     * Assert that a key does NOT exist in the cache.
     */
    public function assertMissing(string $key): void
    {
        Assert::assertFalse(
            $this->has($key),
            sprintf(
                "Expected cache NOT to contain key [%s], but it does.\nValue: %s",
                $key,
                $this->store[$key] ?? '(null)',
            ),
        );
    }

    /**
     * Assert that a key has a specific value.
     */
    public function assertValue(string $key, string $expectedValue): void
    {
        $this->assertHas($key);

        Assert::assertSame(
            $expectedValue,
            $this->store[$key],
            sprintf(
                'Expected cache key [%s] to have value [%s], but got [%s].',
                $key,
                $expectedValue,
                $this->store[$key],
            ),
        );
    }

    /**
     * Assert that a cache operation was performed.
     *
     * @param string $operation One of: get, set, delete, clear, increment
     * @param string|null $key The key involved (null = any)
     */
    public function assertOperation(string $operation, ?string $key = null): void
    {
        $matching = array_filter(
            $this->operations,
            static fn(array $op): bool => $op['operation'] === $operation
                && ($key === null || $op['key'] === $key),
        );

        Assert::assertNotEmpty(
            $matching,
            sprintf(
                "Expected cache operation [%s]%s, but it did not occur.\nRecorded operations: %s",
                $operation,
                $key !== null ? sprintf(' on key [%s]', $key) : '',
                $this->formatOperations(),
            ),
        );
    }

    /**
     * Assert the cache is empty.
     */
    public function assertEmpty(): void
    {
        Assert::assertEmpty(
            $this->store,
            sprintf(
                'Expected cache to be empty, but it contains %d key(s): %s',
                count($this->store),
                $this->formatKeys(),
            ),
        );
    }

    /**
     * Get all recorded operations.
     *
     * @return list<array{operation: string, key: string, value?: string}>
     */
    public function operations(): array
    {
        return $this->operations;
    }

    /**
     * Get the raw store contents.
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->store;
    }

    /**
     * Reset all recorded state.
     */
    public function reset(): void
    {
        $this->store = [];
        $this->operations = [];
    }

    private function formatKeys(): string
    {
        $keys = array_keys($this->store);

        return $keys === [] ? '(empty)' : implode(', ', $keys);
    }

    private function formatOperations(): string
    {
        if ($this->operations === []) {
            return '(none)';
        }

        $parts = [];

        foreach ($this->operations as $op) {
            $parts[] = sprintf('%s(%s)', $op['operation'], $op['key']);
        }

        return implode(', ', $parts);
    }
}
