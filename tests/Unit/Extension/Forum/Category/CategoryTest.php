<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Forum\Category;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Forum\Category\Category;

#[CoversClass(Category::class)]
final class CategoryTest extends TestCase
{
    #[Test]
    public function createFactoryProducesUnlockedCategory(): void
    {
        $cat = Category::create(id: 'cat-001', slug: 'general', tenantId: 'tenant-001', parentId: null, sortOrder: 0);
        self::assertSame('cat-001', $cat->id);
        self::assertSame('tenant-001', $cat->tenantId);
        self::assertNull($cat->parentId);
        self::assertSame('general', $cat->slug);
        self::assertSame(0, $cat->sortOrder);
        self::assertFalse($cat->isLocked);
    }

    #[Test]
    public function createWithParent(): void
    {
        $cat = Category::create(id: 'cat-002', slug: 'sub-general', parentId: 'cat-001');
        self::assertSame('cat-001', $cat->parentId);
    }

    #[Test]
    public function createWithCustomSortOrder(): void
    {
        $cat = Category::create(id: 'cat-001', slug: 'slug', sortOrder: 5);
        self::assertSame(5, $cat->sortOrder);
    }

    #[Test]
    public function reparentChangesParent(): void
    {
        $cat = Category::create(id: 'cat-001', slug: 'slug');
        $reparented = $cat->reparent('cat-002');
        self::assertSame('cat-002', $reparented->parentId);
        self::assertNull($cat->parentId);
    }

    #[Test]
    public function reparentToNullMakesTopLevel(): void
    {
        $cat = Category::create(id: 'cat-001', slug: 'slug', parentId: 'cat-002');
        $reparented = $cat->reparent(null);
        self::assertNull($reparented->parentId);
    }

    #[Test]
    public function reorderChangesSortOrder(): void
    {
        $cat = Category::create(id: 'cat-001', slug: 'slug', sortOrder: 0);
        $reordered = $cat->reorder(3);
        self::assertSame(3, $reordered->sortOrder);
        self::assertSame(0, $cat->sortOrder);
    }

    #[Test]
    public function lockSetsIsLockedTrue(): void
    {
        $cat = Category::create(id: 'cat-001', slug: 'slug');
        $locked = $cat->lock();
        self::assertTrue($locked->isLocked);
        self::assertFalse($cat->isLocked);
    }

    #[Test]
    public function unlockSetsIsLockedFalse(): void
    {
        $cat = Category::create(id: 'cat-001', slug: 'slug');
        $unlocked = $cat->lock()->unlock();
        self::assertFalse($unlocked->isLocked);
    }

    #[Test]
    public function changeSlugUpdatesSlug(): void
    {
        $cat = Category::create(id: 'cat-001', slug: 'old-slug');
        $changed = $cat->changeSlug('new-slug');
        self::assertSame('new-slug', $changed->slug);
        self::assertSame('old-slug', $cat->slug);
    }

    #[Test]
    public function createWithoutTenantIsNull(): void
    {
        $cat = Category::create(id: 'cat-001', slug: 'slug');
        self::assertNull($cat->tenantId);
    }
}
