<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api\Resource;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Exception\ApiException;
use Pulsar\Api\Resource\ResourceMetadataCache;
use Pulsar\Tests\Unit\Api\Resource\Fixture\NoAttributeResource;
use Pulsar\Tests\Unit\Api\Resource\Fixture\TestUserResource;

final class ResourceMetadataCacheTest extends TestCase
{
    #[Test]
    public function has_returns_false_for_uncached_class(): void
    {
        $cache = new ResourceMetadataCache();

        self::assertFalse($cache->has(TestUserResource::class));
    }

    #[Test]
    public function get_resolves_and_caches_metadata(): void
    {
        $cache = new ResourceMetadataCache();

        $metadata = $cache->get(TestUserResource::class);

        self::assertSame('users', $metadata->resourceType);
        self::assertSame(TestUserResource::class, $metadata->resourceClass);
        self::assertTrue($cache->has(TestUserResource::class));
    }

    #[Test]
    public function get_returns_same_instance_on_repeated_calls(): void
    {
        $cache = new ResourceMetadataCache();

        $first = $cache->get(TestUserResource::class);
        $second = $cache->get(TestUserResource::class);

        self::assertSame($first, $second);
    }

    #[Test]
    public function clear_removes_all_cached_entries(): void
    {
        $cache = new ResourceMetadataCache();
        (void) $cache->get(TestUserResource::class);

        self::assertTrue($cache->has(TestUserResource::class));

        $cache->clear();

        self::assertFalse($cache->has(TestUserResource::class));
    }

    #[Test]
    public function warm_up_populates_cache_for_multiple_classes(): void
    {
        $cache = new ResourceMetadataCache();
        $cache->warmUp([TestUserResource::class]);

        self::assertTrue($cache->has(TestUserResource::class));

        $metadata = $cache->get(TestUserResource::class);
        self::assertSame('users', $metadata->resourceType);
    }

    #[Test]
    public function warm_up_skips_already_cached_entries(): void
    {
        $cache = new ResourceMetadataCache();

        $first = $cache->get(TestUserResource::class);
        $cache->warmUp([TestUserResource::class]);
        $second = $cache->get(TestUserResource::class);

        self::assertSame($first, $second);
    }

    #[Test]
    public function get_throws_for_class_without_api_resource_attribute(): void
    {
        $cache = new ResourceMetadataCache();

        $this->expectException(ApiException::class);

        (void) $cache->get(NoAttributeResource::class);
    }

    #[Test]
    public function warm_up_throws_for_class_without_api_resource_attribute(): void
    {
        $cache = new ResourceMetadataCache();

        $this->expectException(ApiException::class);

        $cache->warmUp([NoAttributeResource::class]);
    }

    #[Test]
    public function resolved_metadata_contains_exposed_fields(): void
    {
        $cache = new ResourceMetadataCache();

        $metadata = $cache->get(TestUserResource::class);
        $fieldNames = $metadata->exposedFieldNames();

        self::assertContains('id', $fieldNames);
        self::assertContains('name', $fieldNames);
        self::assertContains('email', $fieldNames);
        self::assertContains('ssn', $fieldNames);
        self::assertContains('adminNotes', $fieldNames);
        // Non-exposed fields must not appear
        self::assertNotContains('passwordHash', $fieldNames);
        self::assertNotContains('internalNotes', $fieldNames);
        self::assertNotContains('secretScore', $fieldNames);
    }

    #[Test]
    public function clear_then_get_resolves_fresh_instance(): void
    {
        $cache = new ResourceMetadataCache();

        $first = $cache->get(TestUserResource::class);
        $cache->clear();
        $second = $cache->get(TestUserResource::class);

        // Same data, but a new instance since cache was cleared
        self::assertNotSame($first, $second);
        self::assertSame($first->resourceType, $second->resourceType);
    }
}
