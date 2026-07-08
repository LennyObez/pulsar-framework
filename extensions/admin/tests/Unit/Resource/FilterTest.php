<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Resource\BooleanFilter;
use Pulsar\Extension\Admin\Resource\DateRangeFilter;
use Pulsar\Extension\Admin\Resource\SelectFilter;

#[CoversClass(SelectFilter::class)]
#[CoversClass(DateRangeFilter::class)]
#[CoversClass(BooleanFilter::class)]
final class FilterTest extends TestCase
{
    #[Test]
    public function selectFilterMake(): void
    {
        $filter = SelectFilter::make('status');

        self::assertSame('status', $filter->field);
        self::assertSame('Status', $filter->label);
        self::assertSame('select', $filter->type);
    }

    #[Test]
    public function selectFilterCustomLabel(): void
    {
        $filter = SelectFilter::make('status', 'User Status');

        self::assertSame('User Status', $filter->label);
    }

    #[Test]
    public function selectFilterWithOptions(): void
    {
        $filter = SelectFilter::make('role')->options(['admin', 'user']);

        self::assertSame(['admin', 'user'], $filter->getOptions());
    }

    #[Test]
    public function selectFilterWithEnumOptions(): void
    {
        $filter = SelectFilter::make('status')->options(TestStatus::cases());

        self::assertSame(['active', 'inactive', 'pending'], $filter->getOptions());
    }

    #[Test]
    public function selectFilterApply(): void
    {
        $filter = SelectFilter::make('status');
        $query = $filter->apply([], 'active');

        self::assertSame(['status' => 'active'], $query);
    }

    #[Test]
    public function selectFilterApplyIgnoresEmpty(): void
    {
        $filter = SelectFilter::make('status');
        $query = $filter->apply(['existing' => 'value'], '');

        self::assertSame(['existing' => 'value'], $query);
    }

    #[Test]
    public function dateRangeFilterMake(): void
    {
        $filter = DateRangeFilter::make('created_at');

        self::assertSame('created_at', $filter->field);
        self::assertSame('Created at', $filter->label);
        self::assertSame('date_range', $filter->type);
    }

    #[Test]
    public function dateRangeFilterApply(): void
    {
        $filter = DateRangeFilter::make('created_at');
        $query = $filter->apply([], ['from' => '2024-01-01', 'to' => '2024-12-31']);

        self::assertSame([
            'created_at_from' => '2024-01-01',
            'created_at_to' => '2024-12-31',
        ], $query);
    }

    #[Test]
    public function dateRangeFilterApplyPartial(): void
    {
        $filter = DateRangeFilter::make('updated_at');
        $query = $filter->apply([], ['from' => '2024-06-01']);

        self::assertSame(['updated_at_from' => '2024-06-01'], $query);
    }

    #[Test]
    public function dateRangeFilterApplyIgnoresInvalid(): void
    {
        $filter = DateRangeFilter::make('created_at');
        $query = $filter->apply([], 'not-an-array');

        self::assertSame([], $query);
    }

    #[Test]
    public function booleanFilterMake(): void
    {
        $filter = BooleanFilter::make('is_active');

        self::assertSame('is_active', $filter->field);
        self::assertSame('Is active', $filter->label);
        self::assertSame('boolean', $filter->type);
    }

    #[Test]
    public function booleanFilterApplyTrue(): void
    {
        $filter = BooleanFilter::make('is_active');

        self::assertSame(['is_active' => true], $filter->apply([], true));
        self::assertSame(['is_active' => true], $filter->apply([], 'true'));
        self::assertSame(['is_active' => true], $filter->apply([], '1'));
    }

    #[Test]
    public function booleanFilterApplyFalse(): void
    {
        $filter = BooleanFilter::make('is_active');

        self::assertSame(['is_active' => false], $filter->apply([], false));
        self::assertSame(['is_active' => false], $filter->apply([], 'false'));
        self::assertSame(['is_active' => false], $filter->apply([], '0'));
    }

    #[Test]
    public function booleanFilterApplyNull(): void
    {
        $filter = BooleanFilter::make('is_active');

        self::assertSame([], $filter->apply([], null));
    }
}
