<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Features\SavedViews;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Domain\SavedView;
use Pulsar\Extension\Admin\Features\SavedViews\SavedViewsResult;

#[CoversClass(SavedViewsResult::class)]
final class SavedViewsResultTest extends TestCase
{
    #[Test]
    public function successful_list_result(): void
    {
        $view = new SavedView(
            id: 'v1',
            resourceName: 'users',
            label: 'Active',
            filters: [],
            sort: [],
            perPage: 25,
            createdBy: 'admin',
        );

        $result = new SavedViewsResult(success: true, views: [$view]);

        self::assertTrue($result->success);
        self::assertCount(1, $result->views);
        self::assertNull($result->view);
    }

    #[Test]
    public function successful_get_result(): void
    {
        $view = new SavedView(
            id: 'v1',
            resourceName: 'users',
            label: 'Active',
            filters: [],
            sort: [],
            perPage: 25,
            createdBy: 'admin',
        );

        $result = new SavedViewsResult(success: true, view: $view);

        self::assertTrue($result->success);
        self::assertSame($view, $result->view);
        self::assertSame([], $result->views);
    }

    #[Test]
    public function default_values(): void
    {
        $result = new SavedViewsResult(success: false);

        self::assertFalse($result->success);
        self::assertSame([], $result->views);
        self::assertNull($result->view);
    }
}
