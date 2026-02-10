<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Cache;

use DateInterval;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use Pulsar\Database\Cache\QueryCache;
use Pulsar\Database\Result;

use function array_key_exists;

#[CoversClass(QueryCache::class)]
final class QueryCacheTest extends TestCase
{
    private InMemoryCache $memoryCache;

    private QueryCache $queryCache;

    protected function setUp(): void
    {
        $this->memoryCache = new InMemoryCache();
        $this->queryCache = new QueryCache($this->memoryCache);
    }

    #[Test]
    public function getReturnsNullOnMiss(): void
    {
        self::assertNull($this->queryCache->get('nonexistent'));
    }

    #[Test]
    public function putAndGetRoundtrip(): void
    {
        $result = Result::fromArrays([
            ['id' => 1, 'name' => 'Alice'],
            ['id' => 2, 'name' => 'Bob'],
        ]);

        $this->queryCache->put('key1', $result, 60, ['users']);

        $cached = $this->queryCache->get('key1');

        self::assertNotNull($cached);
        self::assertSame(2, $cached->rowCount);
        self::assertSame('Alice', $cached->first()?->getString('name'));
        self::assertSame('Bob', $cached->rows[1]->getString('name'));
    }

    #[Test]
    public function ttlPassedToPsr16(): void
    {
        $result = Result::fromArrays([['id' => 1]]);

        $this->queryCache->put('key1', $result, 120, []);

        self::assertSame(120, $this->memoryCache->getTtl('key1'));
    }

    #[Test]
    public function invalidateByTagsRemovesMatchingEntries(): void
    {
        $result1 = Result::fromArrays([['id' => 1]]);
        $result2 = Result::fromArrays([['id' => 2]]);
        $result3 = Result::fromArrays([['id' => 3]]);

        $this->queryCache->put('key1', $result1, 60, ['users']);
        $this->queryCache->put('key2', $result2, 60, ['users', 'orders']);
        $this->queryCache->put('key3', $result3, 60, ['orders']);

        $this->queryCache->invalidateByTags(['users']);

        self::assertNull($this->queryCache->get('key1'));
        self::assertNull($this->queryCache->get('key2'));
        self::assertNotNull($this->queryCache->get('key3'));
    }

    #[Test]
    public function flushClearsAll(): void
    {
        $result = Result::fromArrays([['id' => 1]]);

        $this->queryCache->put('key1', $result, 60, ['users']);
        $this->queryCache->put('key2', $result, 60, ['orders']);

        $this->queryCache->flush();

        self::assertNull($this->queryCache->get('key1'));
        self::assertNull($this->queryCache->get('key2'));
    }

    #[Test]
    public function resultSerializationRoundtrip(): void
    {
        $result = Result::fromArrays([
            ['id' => 1, 'name' => 'Alice', 'active' => true, 'score' => 95.5],
            ['id' => 2, 'name' => 'Bob', 'active' => false, 'score' => null],
        ]);

        $this->queryCache->put('key1', $result, 60, []);
        $cached = $this->queryCache->get('key1');

        self::assertNotNull($cached);
        self::assertSame(2, $cached->rowCount);

        $first = $cached->rows[0];
        self::assertSame(1, $first->get('id'));
        self::assertSame('Alice', $first->get('name'));
        self::assertTrue($first->get('active'));
        self::assertSame(95.5, $first->get('score'));

        $second = $cached->rows[1];
        self::assertSame(2, $second->get('id'));
        self::assertNull($second->get('score'));
    }
}

/**
 * Minimal in-memory PSR-16 implementation for testing.
 *
 * @internal
 */
final class InMemoryCache implements CacheInterface
{
    /** @var array<string, mixed> */
    private array $store = [];

    /** @var array<string, int|null> */
    private array $ttls = [];

    public function get(string $key, mixed $default = null): mixed
    {
        if (!array_key_exists($key, $this->store)) {
            return $default;
        }

        return $this->store[$key];
    }

    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        $this->store[$key] = $value;
        $this->ttls[$key] = $ttl instanceof DateInterval ? null : $ttl;

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->store[$key], $this->ttls[$key]);

        return true;
    }

    public function clear(): bool
    {
        $this->store = [];
        $this->ttls = [];

        return true;
    }

    /** @param iterable<string> $keys */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];

        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }

        return $result;
    }

    /** @param iterable<mixed, mixed> $values */
    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        /** @var string $key */
        foreach ($values as $key => $value) {
            $this->set($key, $value, $ttl);
        }

        return true;
    }

    /** @param iterable<string> $keys */
    public function deleteMultiple(iterable $keys): bool
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

    public function getTtl(string $key): ?int
    {
        return $this->ttls[$key] ?? null;
    }
}
