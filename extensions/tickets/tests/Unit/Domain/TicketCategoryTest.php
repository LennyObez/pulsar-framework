<?php

declare(strict_types=1);

namespace Pulsar\Extension\Tickets\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Tickets\Domain\TicketCategory;

final class TicketCategoryTest extends TestCase
{
    #[Test]
    public function createSetsDefaults(): void
    {
        $category = TicketCategory::create(
            id: 'cat-1',
            name: 'Billing',
            slug: 'billing',
        );

        self::assertSame('cat-1', $category->id);
        self::assertSame('Billing', $category->name);
        self::assertSame('billing', $category->slug);
        self::assertNull($category->description);
        self::assertNull($category->parentId);
        self::assertSame(0, $category->sortOrder);
    }

    #[Test]
    public function createWithAllParameters(): void
    {
        $category = TicketCategory::create(
            id: 'cat-2',
            name: 'Sub Billing',
            slug: 'sub-billing',
            description: 'Billing subcategory',
            parentId: 'cat-1',
            sortOrder: 5,
        );

        self::assertSame('Billing subcategory', $category->description);
        self::assertSame('cat-1', $category->parentId);
        self::assertSame(5, $category->sortOrder);
    }

    #[Test]
    public function renameUpdatesNameAndSlug(): void
    {
        $category = $this->createCategory();
        $renamed = $category->rename('Technical Support', 'technical-support');

        self::assertSame('Technical Support', $renamed->name);
        self::assertSame('technical-support', $renamed->slug);
        self::assertNotSame($category, $renamed);
    }

    #[Test]
    public function describeUpdatesDescription(): void
    {
        $category = $this->createCategory();
        $described = $category->describe('Handles billing inquiries');

        self::assertSame('Handles billing inquiries', $described->description);
    }

    #[Test]
    public function describeAcceptsNull(): void
    {
        $category = $this->createCategory()->describe('Some text');
        $cleared = $category->describe(null);

        self::assertNull($cleared->description);
    }

    #[Test]
    public function reparentChangesParent(): void
    {
        $category = $this->createCategory();
        $reparented = $category->reparent('parent-2');

        self::assertSame('parent-2', $reparented->parentId);
        self::assertNotSame($category, $reparented);
    }

    #[Test]
    public function reparentAcceptsNullForTopLevel(): void
    {
        $category = TicketCategory::create('c1', 'Test', 'test', parentId: 'parent-1');
        $reparented = $category->reparent(null);

        self::assertNull($reparented->parentId);
    }

    #[Test]
    public function reorderUpdatesSortOrder(): void
    {
        $category = $this->createCategory();
        $reordered = $category->reorder(10);

        self::assertSame(10, $reordered->sortOrder);
        self::assertNotSame($category, $reordered);
    }

    private function createCategory(): TicketCategory
    {
        return TicketCategory::create(
            id: 'cat-test',
            name: 'Billing',
            slug: 'billing',
        );
    }
}
