<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Tests\Unit\Category;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Forum\Category\Category;

final class CategoryTest extends TestCase
{
    #[Test]
    public function createSetsDefaults(): void
    {
        $category = Category::create(id: 'cat-1', slug: 'general');

        self::assertSame('cat-1', $category->id);
        self::assertNull($category->tenantId);
        self::assertNull($category->parentId);
        self::assertSame('general', $category->slug);
        self::assertSame(0, $category->sortOrder);
        self::assertFalse($category->isLocked);
        self::assertEqualsWithDelta(time(), $category->createdAt->getTimestamp(), 2);
        self::assertEqualsWithDelta(time(), $category->updatedAt->getTimestamp(), 2);
    }

    #[Test]
    public function createWithOptionalParams(): void
    {
        $category = Category::create(
            id: 'cat-1',
            slug: 'sub-general',
            tenantId: 'tenant-1',
            parentId: 'cat-0',
            sortOrder: 5,
        );

        self::assertSame('tenant-1', $category->tenantId);
        self::assertSame('cat-0', $category->parentId);
        self::assertSame(5, $category->sortOrder);
    }

    #[Test]
    public function reparentChangesParent(): void
    {
        $category = Category::create(id: 'cat-1', slug: 'test');
        $reparented = $category->reparent('cat-2');

        self::assertSame('cat-2', $reparented->parentId);
        self::assertNull($category->parentId);
    }

    #[Test]
    public function reparentToNullRemovesParent(): void
    {
        $category = Category::create(id: 'cat-1', slug: 'test', parentId: 'cat-2');
        $reparented = $category->reparent(null);

        self::assertNull($reparented->parentId);
    }

    #[Test]
    public function reorderChangesSortOrder(): void
    {
        $category = Category::create(id: 'cat-1', slug: 'test');
        $reordered = $category->reorder(10);

        self::assertSame(10, $reordered->sortOrder);
    }

    #[Test]
    public function lockSetsFlag(): void
    {
        $category = Category::create(id: 'cat-1', slug: 'test');
        $locked = $category->lock();

        self::assertTrue($locked->isLocked);
    }

    #[Test]
    public function unlockClearsFlag(): void
    {
        $category = Category::create(id: 'cat-1', slug: 'test');
        $locked = $category->lock();
        $unlocked = $locked->unlock();

        self::assertFalse($unlocked->isLocked);
    }

    #[Test]
    public function changeSlugUpdatesSlug(): void
    {
        $category = Category::create(id: 'cat-1', slug: 'old-slug');
        $changed = $category->changeSlug('new-slug');

        self::assertSame('new-slug', $changed->slug);
        self::assertSame('old-slug', $category->slug);
    }
}
