<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Domain\SavedView;

#[CoversClass(SavedView::class)]
final class SavedViewTest extends TestCase
{
    #[Test]
    public function constructWithAllProperties(): void
    {
        $view = new SavedView(
            id: 'sv-123',
            resourceName: 'users',
            label: 'Active Users',
            filters: ['status' => 'active'],
            sort: ['name' => 'asc'],
            perPage: 50,
            createdBy: 'admin-1',
            isDefault: true,
            createdAt: 1700000000,
        );

        self::assertSame('sv-123', $view->id);
        self::assertSame('users', $view->resourceName);
        self::assertSame('Active Users', $view->label);
        self::assertSame(['status' => 'active'], $view->filters);
        self::assertSame(['name' => 'asc'], $view->sort);
        self::assertSame(50, $view->perPage);
        self::assertSame('admin-1', $view->createdBy);
        self::assertTrue($view->isDefault);
        self::assertSame(1700000000, $view->createdAt);
    }

    #[Test]
    public function defaultValues(): void
    {
        $view = new SavedView(
            id: 'sv-456',
            resourceName: 'orders',
            label: 'All Orders',
            filters: [],
            sort: [],
            perPage: 25,
            createdBy: 'admin-2',
        );

        self::assertFalse($view->isDefault);
        self::assertSame(0, $view->createdAt);
    }

    #[Test]
    public function complexFilters(): void
    {
        $view = new SavedView(
            id: 'sv-789',
            resourceName: 'products',
            label: 'Expensive',
            filters: ['price_min' => 100, 'category' => ['electronics', 'books']],
            sort: ['price' => 'desc'],
            perPage: 10,
            createdBy: 'admin-3',
        );

        self::assertSame(['price_min' => 100, 'category' => ['electronics', 'books']], $view->filters);
        self::assertSame(['price' => 'desc'], $view->sort);
    }
}
