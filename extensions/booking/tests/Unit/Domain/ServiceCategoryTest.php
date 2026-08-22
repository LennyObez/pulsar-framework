<?php

declare(strict_types=1);

namespace Pulsar\Extension\Booking\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Booking\Domain\ServiceCategory;

#[CoversClass(ServiceCategory::class)]
final class ServiceCategoryTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $category = new ServiceCategory(
            id: 'sc-1',
            name: 'Hair Care',
            slug: 'hair-care',
            sortOrder: 5,
        );

        self::assertSame('sc-1', $category->id);
        self::assertSame('Hair Care', $category->name);
        self::assertSame('hair-care', $category->slug);
        self::assertSame(5, $category->sortOrder);
    }

    #[Test]
    public function sortOrderCanBeZero(): void
    {
        $category = new ServiceCategory(
            id: 'sc-2',
            name: 'Default',
            slug: 'default',
            sortOrder: 0,
        );

        self::assertSame(0, $category->sortOrder);
    }

    #[Test]
    public function differentCategoriesHaveDifferentIds(): void
    {
        $a = new ServiceCategory(id: 'a', name: 'A', slug: 'a', sortOrder: 1);
        $b = new ServiceCategory(id: 'b', name: 'B', slug: 'b', sortOrder: 2);

        self::assertNotSame($a->id, $b->id);
    }
}
