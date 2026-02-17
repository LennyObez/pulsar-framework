<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api\Resource;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Resource\AbstractApiResource;
use Pulsar\Api\Resource\ResourceCollection;

#[CoversClass(ResourceCollection::class)]
final class ResourceCollectionTest extends TestCase
{
    #[Test]
    public function empty_collection(): void
    {
        $collection = new ResourceCollection(items: []);

        self::assertSame(0, $collection->count());
        self::assertTrue($collection->isEmpty());
        self::assertNull($collection->total);
    }

    #[Test]
    public function collection_with_items(): void
    {
        $resource = $this->createStub(AbstractApiResource::class);
        $resource->method('toArray')->willReturn(['id' => 1, 'name' => 'Test']);

        $collection = new ResourceCollection(
            items: [$resource],
            total: 42,
        );

        self::assertSame(1, $collection->count());
        self::assertFalse($collection->isEmpty());
        self::assertSame(42, $collection->total);
    }

    #[Test]
    public function to_array_serializes_items(): void
    {
        $resource1 = $this->createStub(AbstractApiResource::class);
        $resource1->method('toArray')->willReturn(['id' => 1]);

        $resource2 = $this->createStub(AbstractApiResource::class);
        $resource2->method('toArray')->willReturn(['id' => 2]);

        $collection = new ResourceCollection(items: [$resource1, $resource2]);
        $array = $collection->toArray();

        self::assertArrayHasKey('data', $array);
        $data = $array['data'];
        self::assertIsArray($data);
        self::assertCount(2, $data);
        self::assertIsArray($data[0]);
        self::assertSame(1, $data[0]['id']);
        self::assertIsArray($data[1]);
        self::assertSame(2, $data[1]['id']);
    }

    #[Test]
    public function to_array_includes_total_in_meta(): void
    {
        $collection = new ResourceCollection(items: [], total: 100);
        $array = $collection->toArray();

        self::assertArrayHasKey('meta', $array);
        $meta = $array['meta'];
        self::assertIsArray($meta);
        self::assertSame(100, $meta['total']);
    }

    #[Test]
    public function to_array_includes_pagination_meta(): void
    {
        $collection = new ResourceCollection(
            items: [],
            paginationMeta: ['page' => 2, 'per_page' => 25],
        );
        $array = $collection->toArray();

        self::assertArrayHasKey('meta', $array);
        $meta = $array['meta'];
        self::assertIsArray($meta);
        self::assertSame(2, $meta['page']);
        self::assertSame(25, $meta['per_page']);
    }

    #[Test]
    public function to_array_omits_meta_when_empty(): void
    {
        $collection = new ResourceCollection(items: []);
        $array = $collection->toArray();

        self::assertArrayNotHasKey('meta', $array);
    }

    #[Test]
    public function to_array_merges_total_and_pagination_meta(): void
    {
        $collection = new ResourceCollection(
            items: [],
            total: 50,
            paginationMeta: ['cursor' => 'abc123'],
        );
        $array = $collection->toArray();

        $meta = $array['meta'];
        self::assertIsArray($meta);
        self::assertSame(50, $meta['total']);
        self::assertSame('abc123', $meta['cursor']);
    }

    #[Test]
    public function resource_type_stored(): void
    {
        $collection = new ResourceCollection(
            items: [],
            resourceType: 'users',
        );

        self::assertSame('users', $collection->resourceType);
    }
}
