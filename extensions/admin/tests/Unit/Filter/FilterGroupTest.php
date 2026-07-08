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
    public function emptyGroupIsEmpty(): void
    {
        $group = new FilterGroup();

        self::assertTrue($group->isEmpty());
        self::assertSame(0, $group->totalConditions());
    }

    #[Test]
    public function groupWithConditionsIsNotEmpty(): void
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
    public function groupWithNestedGroupsIsNotEmpty(): void
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
    public function totalConditionsCountsNested(): void
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
    public function fromArrayParsesBasicGroup(): void
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
    public function fromArrayDefaultsToAndLogic(): void
    {
        $group = FilterGroup::fromArray([]);

        self::assertSame(FilterLogic::And, $group->logic);
        self::assertSame([], $group->conditions);
        self::assertSame([], $group->groups);
    }

    #[Test]
    public function fromArrayWithNonStringLogicDefaults(): void
    {
        $group = FilterGroup::fromArray(['logic' => 123]);

        self::assertSame(FilterLogic::And, $group->logic);
    }

    #[Test]
    public function fromArrayParsesNestedGroups(): void
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
    public function fromArraySkipsNonArrayConditions(): void
    {
        $group = FilterGroup::fromArray([
            'conditions' => ['not_an_array', 42],
        ]);

        self::assertSame([], $group->conditions);
    }

    #[Test]
    public function fromArraySkipsNonArrayGroups(): void
    {
        $group = FilterGroup::fromArray([
            'groups' => ['not_an_array', 42],
        ]);

        self::assertSame([], $group->groups);
    }

    #[Test]
    public function toArrayProducesCorrectStructure(): void
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
    public function toArrayRoundTrips(): void
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
