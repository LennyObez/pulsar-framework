<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Cache;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Cache\CachedResponse;
use Pulsar\Http\Cache\InMemoryCacheStorage;

#[CoversClass(InMemoryCacheStorage::class)]
final class InMemoryCacheStorageTest extends TestCase
{
    private function createResponse(int $expiresAt = 0): CachedResponse
    {
        return new CachedResponse(
            statusCode: 200,
            headers: ['Content-Type' => ['text/html']],
            body: '<h1>Cached</h1>',
            etag: '"etag-1"',
            createdAt: time(),
            expiresAt: $expiresAt ?: time() + 3600,
        );
    }

    #[Test]
    public function getReturnsNullForMissingKey(): void
    {
        $storage = new InMemoryCacheStorage();

        self::assertNull($storage->get('nonexistent'));
    }

    #[Test]
    public function setAndGetRetrievesCachedResponse(): void
    {
        $storage = new InMemoryCacheStorage();
        $response = $this->createResponse();

        $storage->set('key1', $response, 3600);

        $retrieved = $storage->get('key1');
        self::assertNotNull($retrieved);
        self::assertSame(200, $retrieved->statusCode);
        self::assertSame('<h1>Cached</h1>', $retrieved->body);
    }

    #[Test]
    public function getReturnsNullForExpiredEntry(): void
    {
        $storage = new InMemoryCacheStorage();
        $expired = $this->createResponse(expiresAt: 1); // Already expired

        $storage->set('expired', $expired, 0);

        self::assertNull($storage->get('expired'));
    }

    #[Test]
    public function deleteRemovesEntry(): void
    {
        $storage = new InMemoryCacheStorage();
        $storage->set('key1', $this->createResponse(), 3600);

        $storage->delete('key1');

        self::assertNull($storage->get('key1'));
    }

    #[Test]
    public function deleteNonExistentKeyIsNoOp(): void
    {
        $storage = new InMemoryCacheStorage();

        $storage->delete('nonexistent');

        self::assertNull($storage->get('nonexistent'));
    }

    #[Test]
    public function clearRemovesAllEntries(): void
    {
        $storage = new InMemoryCacheStorage();
        $storage->set('a', $this->createResponse(), 3600);
        $storage->set('b', $this->createResponse(), 3600);
        $storage->set('c', $this->createResponse(), 3600);

        $storage->clear();

        self::assertNull($storage->get('a'));
        self::assertNull($storage->get('b'));
        self::assertNull($storage->get('c'));
    }

    #[Test]
    public function invalidateByTagsRemovesTaggedEntries(): void
    {
        $storage = new InMemoryCacheStorage();
        $storage->set('page-1', $this->createResponse(), 3600, ['pages']);
        $storage->set('page-2', $this->createResponse(), 3600, ['pages']);
        $storage->set('api-1', $this->createResponse(), 3600, ['api']);

        $storage->invalidateByTags(['pages']);

        self::assertNull($storage->get('page-1'));
        self::assertNull($storage->get('page-2'));
        self::assertNotNull($storage->get('api-1'));
    }

    #[Test]
    public function invalidateByMultipleTags(): void
    {
        $storage = new InMemoryCacheStorage();
        $storage->set('page-1', $this->createResponse(), 3600, ['pages', 'content']);
        $storage->set('api-1', $this->createResponse(), 3600, ['api']);
        $storage->set('style-1', $this->createResponse(), 3600, ['assets']);

        $storage->invalidateByTags(['pages', 'assets']);

        self::assertNull($storage->get('page-1'));
        self::assertNotNull($storage->get('api-1'));
        self::assertNull($storage->get('style-1'));
    }

    #[Test]
    public function lruEvictionRemovesOldestWhenMaxReached(): void
    {
        $storage = new InMemoryCacheStorage(maxEntries: 3);

        // Fill to capacity with ascending expiry
        $storage->set('first', $this->createResponse(expiresAt: time() + 100), 100);
        $storage->set('second', $this->createResponse(expiresAt: time() + 200), 200);
        $storage->set('third', $this->createResponse(expiresAt: time() + 300), 300);

        // Adding a 4th should evict the one with earliest expiry (first)
        $storage->set('fourth', $this->createResponse(expiresAt: time() + 400), 400);

        self::assertNull($storage->get('first'));
        self::assertNotNull($storage->get('second'));
        self::assertNotNull($storage->get('third'));
        self::assertNotNull($storage->get('fourth'));
    }

    #[Test]
    public function overwritingExistingKeyDoesNotTriggerEviction(): void
    {
        $storage = new InMemoryCacheStorage(maxEntries: 2);

        $storage->set('a', $this->createResponse(), 3600);
        $storage->set('b', $this->createResponse(), 3600);

        // Overwrite 'a' — should not evict since key already exists
        $newResponse = new CachedResponse(
            statusCode: 201,
            headers: [],
            body: 'Updated',
            etag: '"new"',
            createdAt: time(),
            expiresAt: time() + 3600,
        );
        $storage->set('a', $newResponse, 3600);

        $retrieved = $storage->get('a');
        self::assertNotNull($retrieved);
        self::assertSame(201, $retrieved->statusCode);
        self::assertNotNull($storage->get('b'));
    }

    #[Test]
    public function tagCleanupOnDelete(): void
    {
        $storage = new InMemoryCacheStorage();
        $storage->set('tagged', $this->createResponse(), 3600, ['tag1']);

        $storage->delete('tagged');

        // Adding new entry with same tag should work without issues
        $storage->set('new-tagged', $this->createResponse(), 3600, ['tag1']);

        // Invalidating the tag should only affect the new entry
        $storage->invalidateByTags(['tag1']);
        self::assertNull($storage->get('new-tagged'));
    }
}
