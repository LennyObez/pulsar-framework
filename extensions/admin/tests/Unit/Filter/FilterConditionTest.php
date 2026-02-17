<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Filter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Filter\FilterCondition;
use Pulsar\Extension\Admin\Filter\FilterOperator;

#[CoversClass(FilterCondition::class)]
final class FilterConditionTest extends TestCase
{
    #[Test]
    public function constructor_sets_properties(): void
    {
        $condition = new FilterCondition(
            field: 'name',
            operator: FilterOperator::Equals,
            value: 'John',
        );

        self::assertSame('name', $condition->field);
        self::assertSame(FilterOperator::Equals, $condition->operator);
        self::assertSame('John', $condition->value);
    }

    #[Test]
    public function constructor_value_defaults_to_null(): void
    {
        $condition = new FilterCondition(
            field: 'status',
            operator: FilterOperator::IsNull,
        );

        self::assertNull($condition->value);
    }

    #[Test]
    public function from_array_parses_condition(): void
    {
        $condition = FilterCondition::fromArray([
            'field' => 'age',
            'operator' => 'gt',
            'value' => 18,
        ]);

        self::assertSame('age', $condition->field);
        self::assertSame(FilterOperator::GreaterThan, $condition->operator);
        self::assertSame(18, $condition->value);
    }

    #[Test]
    public function from_array_defaults_empty_field(): void
    {
        $condition = FilterCondition::fromArray([]);

        self::assertSame('', $condition->field);
        self::assertSame(FilterOperator::Equals, $condition->operator);
        self::assertNull($condition->value);
    }

    #[Test]
    public function from_array_with_non_string_field_defaults_to_empty(): void
    {
        $condition = FilterCondition::fromArray([
            'field' => 123,
            'operator' => 'eq',
        ]);

        self::assertSame('', $condition->field);
    }

    #[Test]
    public function from_array_with_non_string_operator_defaults_to_eq(): void
    {
        $condition = FilterCondition::fromArray([
            'field' => 'name',
            'operator' => 999,
        ]);

        self::assertSame(FilterOperator::Equals, $condition->operator);
    }

    #[Test]
    public function to_array_produces_correct_structure(): void
    {
        $condition = new FilterCondition(
            field: 'email',
            operator: FilterOperator::Contains,
            value: '@example.com',
        );

        $array = $condition->toArray();

        self::assertSame('email', $array['field']);
        self::assertSame('contains', $array['operator']);
        self::assertSame('@example.com', $array['value']);
    }

    #[Test]
    public function to_array_round_trips_through_from_array(): void
    {
        $original = new FilterCondition(
            field: 'status',
            operator: FilterOperator::In,
            value: ['active', 'pending'],
        );

        $restored = FilterCondition::fromArray($original->toArray());

        self::assertSame($original->field, $restored->field);
        self::assertSame($original->operator, $restored->operator);
        self::assertSame($original->value, $restored->value);
    }
}
