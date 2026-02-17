<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Domain\SavedView;

final class SavedViewTest extends TestCase
{
    #[Test]
    public function construction_with_defaults(): void
    {
        $view = new SavedView(
            id: 'sv_001',
            resourceName: 'users',
            label: 'Active Users',
            filters: ['status' => 'active'],
            sort: ['created_at' => 'desc'],
            perPage: 25,
            createdBy: 'admin@test.com',
        );

        self::assertSame('sv_001', $view->id);
        self::assertSame('users', $view->resourceName);
        self::assertSame('Active Users', $view->label);
        self::assertSame(['status' => 'active'], $view->filters);
        self::assertSame(['created_at' => 'desc'], $view->sort);
        self::assertSame(25, $view->perPage);
        self::assertSame('admin@test.com', $view->createdBy);
        self::assertFalse($view->isDefault);
        self::assertSame(0, $view->createdAt);
    }

    #[Test]
    public function construction_fully_specified(): void
    {
        $view = new SavedView(
            id: 'sv_002',
            resourceName: 'orders',
            label: 'Pending Orders',
            filters: ['status' => 'pending', 'total' => '>100'],
            sort: ['total' => 'asc'],
            perPage: 50,
            createdBy: 'manager@test.com',
            isDefault: true,
            createdAt: 1700000000,
        );

        self::assertSame('sv_002', $view->id);
        self::assertTrue($view->isDefault);
        self::assertSame(1700000000, $view->createdAt);
        self::assertSame(50, $view->perPage);
    }

    #[Test]
    public function empty_filters_and_sort(): void
    {
        $view = new SavedView(
            id: 'sv_003',
            resourceName: 'products',
            label: 'All Products',
            filters: [],
            sort: [],
            perPage: 100,
            createdBy: 'test',
        );

        self::assertSame([], $view->filters);
        self::assertSame([], $view->sort);
    }
}
