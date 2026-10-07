<?php

declare(strict_types=1);

namespace Pulsar\Extension\Tickets\Tests\Unit\Contracts;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Tickets\Contracts\TicketCategoryRepositoryInterface;
use Pulsar\Extension\Tickets\Domain\TicketCategory;

#[CoversNothing]
final class TicketCategoryRepositoryInterfaceTest extends TestCase
{
    #[Test]
    public function stubCanReturnCategoryById(): void
    {
        $category = TicketCategory::create('cat-1', 'Billing', 'billing');

        $stub = $this->createStub(TicketCategoryRepositoryInterface::class);
        $stub->method('findById')->willReturn($category);

        self::assertSame($category, $stub->findById('cat-1'));
    }

    #[Test]
    public function stubCanReturnNullById(): void
    {
        $stub = $this->createStub(TicketCategoryRepositoryInterface::class);
        $stub->method('findById')->willReturn(null);

        self::assertNull($stub->findById('nonexistent'));
    }

    #[Test]
    public function stubCanReturnCategoryBySlug(): void
    {
        $category = TicketCategory::create('cat-2', 'Technical', 'technical');

        $stub = $this->createStub(TicketCategoryRepositoryInterface::class);
        $stub->method('findBySlug')->willReturn($category);

        $result = $stub->findBySlug('technical');
        self::assertSame('Technical', $result->name);
    }

    #[Test]
    public function stubCanReturnAllCategories(): void
    {
        $cat1 = TicketCategory::create('cat-1', 'Billing', 'billing');
        $cat2 = TicketCategory::create('cat-2', 'Technical', 'technical');

        $stub = $this->createStub(TicketCategoryRepositoryInterface::class);
        $stub->method('findAll')->willReturn([$cat1, $cat2]);

        $all = $stub->findAll();
        self::assertCount(2, $all);
        self::assertSame('Billing', $all[0]->name);
    }

    #[Test]
    public function stubCanReturnChildCategories(): void
    {
        $child = TicketCategory::create('cat-3', 'Refunds', 'refunds', parentId: 'cat-1');

        $stub = $this->createStub(TicketCategoryRepositoryInterface::class);
        $stub->method('findByParent')->willReturn([$child]);

        $children = $stub->findByParent('cat-1');
        self::assertCount(1, $children);
        self::assertSame('cat-1', $children[0]->parentId);
    }

    #[Test]
    public function stubCanReturnRootCategories(): void
    {
        $root = TicketCategory::create('cat-1', 'General', 'general');

        $stub = $this->createStub(TicketCategoryRepositoryInterface::class);
        $stub->method('findByParent')->willReturn([$root]);

        $roots = $stub->findByParent(null);
        self::assertCount(1, $roots);
        self::assertNull($roots[0]->parentId);
    }
}
