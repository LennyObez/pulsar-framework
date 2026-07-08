<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Filter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Filter\FilterOperator;

#[CoversClass(FilterOperator::class)]
final class FilterOperatorTest extends TestCase
{
    #[Test]
    public function casesCount(): void
    {
        self::assertCount(15, FilterOperator::cases());
    }

    #[Test]
    #[DataProvider('requiresValueProvider')]
    public function requiresValue(FilterOperator $op, bool $expected): void
    {
        self::assertSame($expected, $op->requiresValue());
    }

    /**
     * @return iterable<string, array{FilterOperator, bool}>
     */
    public static function requiresValueProvider(): iterable
    {
        yield 'Equals requires value' => [FilterOperator::Equals, true];
        yield 'NotEquals requires value' => [FilterOperator::NotEquals, true];
        yield 'GreaterThan requires value' => [FilterOperator::GreaterThan, true];
        yield 'Contains requires value' => [FilterOperator::Contains, true];
        yield 'In requires value' => [FilterOperator::In, true];
        yield 'Between requires value' => [FilterOperator::Between, true];
        yield 'IsNull does not require value' => [FilterOperator::IsNull, false];
        yield 'IsNotNull does not require value' => [FilterOperator::IsNotNull, false];
    }

    #[Test]
    #[DataProvider('multiValueProvider')]
    public function isMultiValue(FilterOperator $op, bool $expected): void
    {
        self::assertSame($expected, $op->isMultiValue());
    }

    /**
     * @return iterable<string, array{FilterOperator, bool}>
     */
    public static function multiValueProvider(): iterable
    {
        yield 'In is multi' => [FilterOperator::In, true];
        yield 'NotIn is multi' => [FilterOperator::NotIn, true];
        yield 'Between is multi' => [FilterOperator::Between, true];
        yield 'Equals is not multi' => [FilterOperator::Equals, false];
        yield 'Contains is not multi' => [FilterOperator::Contains, false];
        yield 'IsNull is not multi' => [FilterOperator::IsNull, false];
    }

    #[Test]
    #[DataProvider('sqlProvider')]
    public function toSqlReturnsCorrectOperator(FilterOperator $op, string $expected): void
    {
        self::assertSame($expected, $op->toSql());
    }

    /**
     * @return iterable<string, array{FilterOperator, string}>
     */
    public static function sqlProvider(): iterable
    {
        yield 'Equals' => [FilterOperator::Equals, '='];
        yield 'NotEquals' => [FilterOperator::NotEquals, '!='];
        yield 'GreaterThan' => [FilterOperator::GreaterThan, '>'];
        yield 'GreaterThanOrEqual' => [FilterOperator::GreaterThanOrEqual, '>='];
        yield 'LessThan' => [FilterOperator::LessThan, '<'];
        yield 'LessThanOrEqual' => [FilterOperator::LessThanOrEqual, '<='];
        yield 'Contains' => [FilterOperator::Contains, 'LIKE'];
        yield 'NotContains' => [FilterOperator::NotContains, 'NOT LIKE'];
        yield 'StartsWith' => [FilterOperator::StartsWith, 'LIKE'];
        yield 'EndsWith' => [FilterOperator::EndsWith, 'LIKE'];
        yield 'In' => [FilterOperator::In, 'IN'];
        yield 'NotIn' => [FilterOperator::NotIn, 'NOT IN'];
        yield 'IsNull' => [FilterOperator::IsNull, 'IS NULL'];
        yield 'IsNotNull' => [FilterOperator::IsNotNull, 'IS NOT NULL'];
        yield 'Between' => [FilterOperator::Between, 'BETWEEN'];
    }
}
