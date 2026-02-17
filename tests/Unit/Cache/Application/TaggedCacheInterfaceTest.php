<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cache\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\Application\TaggedCacheInterface;

#[CoversClass(TaggedCacheInterface::class)]
final class TaggedCacheInterfaceTest extends TestCase
{
    #[Test]
    public function implementationStoresAndRetrievesValues(): void
    {
        $storage = [];

        $cache = new class ($storage) implements TaggedCacheInterface {
            /** @param array<string, mixed> $storage */
            public function __construct(private array &$storage) {}

            public function get(string $key): mixed
            {
                return $this->storage[$key] ?? null;
            }

            public function set(string $key, mixed $value, array $tags, ?int $ttlSeconds = null): bool
            {
                $this->storage[$key] = $value;

                return true;
            }

            public function delete(string $key): bool
            {
                unset($this->storage[$key]);

                return true;
            }

            public function invalidateTag(string $tag): void
            {
                $this->storage = [];
            }

            public function invalidateTags(array $tags): void
            {
                $this->storage = [];
            }
        };

        self::assertNull($cache->get('nonexistent'));

        $cache->set('key1', 'value1', ['tag-a'], 60);
        self::assertSame('value1', $cache->get('key1'));

        $cache->delete('key1');
        self::assertNull($cache->get('key1'));
    }

    #[Test]
    public function invalidateTagClearsTaggedEntries(): void
    {
        $storage = [];

        $cache = new class ($storage) implements TaggedCacheInterface {
            /** @param array<string, mixed> $storage */
            public function __construct(private array &$storage) {}

            public function get(string $key): mixed
            {
                return $this->storage[$key] ?? null;
            }

            public function set(string $key, mixed $value, array $tags, ?int $ttlSeconds = null): bool
            {
                $this->storage[$key] = $value;

                return true;
            }

            public function delete(string $key): bool
            {
                unset($this->storage[$key]);

                return true;
            }

            public function invalidateTag(string $tag): void
            {
                $this->storage = [];
            }

            public function invalidateTags(array $tags): void
            {
                $this->storage = [];
            }
        };

        $cache->set('k1', 'v1', ['users']);
        $cache->set('k2', 'v2', ['users']);
        $cache->invalidateTag('users');

        self::assertNull($cache->get('k1'));
        self::assertNull($cache->get('k2'));
    }

    #[Test]
    public function invalidateTagsAcceptsMultipleTags(): void
    {
        $cache = new class implements TaggedCacheInterface {
            /** @var list<list<string>> */
            public array $invalidated = [];

            public function get(string $key): mixed
            {
                return null;
            }
            public function set(string $key, mixed $value, array $tags, ?int $ttlSeconds = null): bool
            {
                return true;
            }
            public function delete(string $key): bool
            {
                return true;
            }
            public function invalidateTag(string $tag): void {}

            public function invalidateTags(array $tags): void
            {
                $this->invalidated[] = $tags;
            }
        };

        $cache->invalidateTags(['users', 'posts', 'comments']);

        self::assertSame([['users', 'posts', 'comments']], $cache->invalidated);
    }

    #[Test]
    public function setWithNullTtlUsesNoExpiration(): void
    {
        $cache = new class implements TaggedCacheInterface {
            /** @var int|null|false */
            public int|null|false $receivedTtl = false;

            public function get(string $key): mixed
            {
                return null;
            }

            public function set(string $key, mixed $value, array $tags, ?int $ttlSeconds = null): bool
            {
                $this->receivedTtl = $ttlSeconds;

                return true;
            }

            public function delete(string $key): bool
            {
                return true;
            }
            public function invalidateTag(string $tag): void {}
            public function invalidateTags(array $tags): void {}
        };

        $cache->set('key', 'value', ['tag']);

        self::assertNull($cache->receivedTtl);
    }
}
