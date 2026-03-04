<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Filter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Filter\FilterCondition;
use Pulsar\Extension\Admin\Filter\FilterGroup;
use Pulsar\Extension\Admin\Filter\FilterLogic;
use Pulsar\Extension\Admin\Filter\FilterOperator;

#[CoversClass(FilterGroup::class)]
final class FilterGroupTest extends TestCase
{
    #[Test]
    public function empty_group_is_empty(): void
    {
        $group = new FilterGroup();

        self::assertTrue($group->isEmpty());
        self::assertSame(0, $group->totalConditions());
    }

    #[Test]
    public function group_with_conditions_is_not_empty(): void
    {
        $group = new FilterGroup(
            conditions: [
                new FilterCondition('name', FilterOperator::Equals, 'John'),
            ],
        );

        self::assertFalse($group->isEmpty());
        self::assertSame(1, $group->totalConditions());
    }

    #[Test]
    public function group_with_nested_groups_is_not_empty(): void
    {
        $nested = new FilterGroup(
            conditions: [
                new FilterCondition('age', FilterOperator::GreaterThan, 18),
            ],
        );
        $group = new FilterGroup(groups: [$nested]);

        self::assertFalse($group->isEmpty());
    }

    #[Test]
    public function total_conditions_counts_nested(): void
    {
        $nested = new FilterGroup(
            conditions: [
                new FilterCondition('age', FilterOperator::GreaterThan, 18),
                new FilterCondition('role', FilterOperator::Equals, 'admin'),
            ],
        );
        $group = new FilterGroup(
            conditions: [
                new FilterCondition('name', FilterOperator::Contains, 'doe'),
            ],
            groups: [$nested],
        );

        self::assertSame(3, $group->totalConditions());
    }

    #[Test]
    public function from_array_parses_basic_group(): void
    {
        $group = FilterGroup::fromArray([
            'logic' => 'or',
            'conditions' => [
                ['field' => 'status', 'operator' => 'eq', 'value' => 'active'],
            ],
        ]);

        self::assertSame(FilterLogic::Or, $group->logic);
        self::assertCount(1, $group->conditions);
        self::assertSame('status', $group->conditions[0]->field);
    }

    #[Test]
    public function from_array_defaults_to_and_logic(): void
    {
        $group = FilterGroup::fromArray([]);

        self::assertSame(FilterLogic::And, $group->logic);
        self::assertSame([], $group->conditions);
        self::assertSame([], $group->groups);
    }

    #[Test]
    public function from_array_with_non_string_logic_defaults(): void
    {
        $group = FilterGroup::fromArray(['logic' => 123]);

        self::assertSame(FilterLogic::And, $group->logic);
    }

    #[Test]
    public function from_array_parses_nested_groups(): void
    {
        $group = FilterGroup::fromArray([
            'logic' => 'and',
            'conditions' => [],
            'groups' => [
                [
                    'logic' => 'or',
                    'conditions' => [
                        ['field' => 'a', 'operator' => 'eq', 'value' => 1],
                        ['field' => 'b', 'operator' => 'eq', 'value' => 2],
                    ],
                ],
            ],
        ]);

        self::assertCount(1, $group->groups);
        self::assertSame(FilterLogic::Or, $group->groups[0]->logic);
        self::assertCount(2, $group->groups[0]->conditions);
    }

    #[Test]
    public function from_array_skips_non_array_conditions(): void
    {
        $group = FilterGroup::fromArray([
            'conditions' => ['not_an_array', 42],
        ]);

        self::assertSame([], $group->conditions);
    }

    #[Test]
    public function from_array_skips_non_array_groups(): void
    {
        $group = FilterGroup::fromArray([
            'groups' => ['not_an_array', 42],
        ]);

        self::assertSame([], $group->groups);
    }

    #[Test]
    public function to_array_produces_correct_structure(): void
    {
        $group = new FilterGroup(
            logic: FilterLogic::Or,
            conditions: [
                new FilterCondition('name', FilterOperator::Equals, 'test'),
            ],
            groups: [],
        );

        $array = $group->toArray();

        self::assertSame('or', $array['logic']);
        self::assertCount(1, $array['conditions']);
        self::assertSame('name', $array['conditions'][0]['field']);
        self::assertSame([], $array['groups']);
    }

    #[Test]
    public function to_array_round_trips(): void
    {
        $original = new FilterGroup(
            logic: FilterLogic::And,
            conditions: [
                new FilterCondition('status', FilterOperator::In, ['active', 'pending']),
            ],
            groups: [
                new FilterGroup(
                    logic: FilterLogic::Or,
                    conditions: [
                        new FilterCondition('age', FilterOperator::GreaterThan, 18),
                    ],
                ),
            ],
        );

        $restored = FilterGroup::fromArray($original->toArray());

        self::assertSame($original->logic, $restored->logic);
        self::assertCount(1, $restored->conditions);
        self::assertCount(1, $restored->groups);
        self::assertSame(FilterLogic::Or, $restored->groups[0]->logic);
    }
}
