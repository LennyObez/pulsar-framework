<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Filter;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Filter\FilterCompiler;
use Pulsar\Extension\Admin\Filter\FilterCondition;
use Pulsar\Extension\Admin\Filter\FilterGroup;
use Pulsar\Extension\Admin\Filter\FilterLogic;
use Pulsar\Extension\Admin\Filter\FilterOperator;

final class FilterCompilerTest extends TestCase
{
    private FilterCompiler $compiler;

    protected function setUp(): void
    {
        $this->compiler = new FilterCompiler();
    }

    #[Test]
    public function compileEmptyGroupReturns1Equals1(): void
    {
        $group = new FilterGroup();

        $result = $this->compiler->compile($group);

        self::assertSame('1=1', $result['sql']);
        self::assertSame([], $result['params']);
    }

    #[Test]
    public function compileSingleEqualsCondition(): void
    {
        $group = new FilterGroup(
            conditions: [
                new FilterCondition('status', FilterOperator::Equals, 'active'),
            ],
        );

        $result = $this->compiler->compile($group);

        self::assertSame('status = :f_0', $result['sql']);
        self::assertSame(['f_0' => 'active'], $result['params']);
    }

    #[Test]
    public function compileMultipleAndConditions(): void
    {
        $group = new FilterGroup(
            logic: FilterLogic::And,
            conditions: [
                new FilterCondition('name', FilterOperator::Contains, 'john'),
                new FilterCondition('age', FilterOperator::GreaterThan, 18),
            ],
        );

        $result = $this->compiler->compile($group);

        self::assertStringContainsString('name LIKE :f_0', $result['sql']);
        self::assertStringContainsString(' AND ', $result['sql']);
        self::assertStringContainsString('age > :f_1', $result['sql']);
        self::assertSame('%john%', $result['params']['f_0']);
        self::assertSame(18, $result['params']['f_1']);
    }

    #[Test]
    public function compileOrConditions(): void
    {
        $group = new FilterGroup(
            logic: FilterLogic::Or,
            conditions: [
                new FilterCondition('role', FilterOperator::Equals, 'admin'),
                new FilterCondition('role', FilterOperator::Equals, 'editor'),
            ],
        );

        $result = $this->compiler->compile($group);

        self::assertStringContainsString(' OR ', $result['sql']);
    }

    #[Test]
    public function compileIsNullOperatorHasNoValue(): void
    {
        $group = new FilterGroup(
            conditions: [
                new FilterCondition('deleted_at', FilterOperator::IsNull),
            ],
        );

        $result = $this->compiler->compile($group);

        self::assertSame('deleted_at IS NULL', $result['sql']);
        self::assertSame([], $result['params']);
    }

    #[Test]
    public function compileIsNotNullOperator(): void
    {
        $group = new FilterGroup(
            conditions: [
                new FilterCondition('email', FilterOperator::IsNotNull),
            ],
        );

        $result = $this->compiler->compile($group);

        self::assertSame('email IS NOT NULL', $result['sql']);
    }

    #[Test]
    public function compileInOperator(): void
    {
        $group = new FilterGroup(
            conditions: [
                new FilterCondition('status', FilterOperator::In, ['active', 'pending', 'review']),
            ],
        );

        $result = $this->compiler->compile($group);

        self::assertStringContainsString('IN (', $result['sql']);
        self::assertCount(3, $result['params']);
    }

    #[Test]
    public function compileBetweenOperator(): void
    {
        $group = new FilterGroup(
            conditions: [
                new FilterCondition('created_at', FilterOperator::Between, ['2025-01-01', '2025-12-31']),
            ],
        );

        $result = $this->compiler->compile($group);

        self::assertStringContainsString('BETWEEN', $result['sql']);
        self::assertCount(2, $result['params']);
    }

    #[Test]
    public function compileBetweenRejectsInvalidValueCount(): void
    {
        $group = new FilterGroup(
            conditions: [
                new FilterCondition('price', FilterOperator::Between, [100]),
            ],
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('exactly 2 values');

        $this->compiler->compile($group);
    }

    #[Test]
    public function compileStartsWithOperator(): void
    {
        $group = new FilterGroup(
            conditions: [
                new FilterCondition('name', FilterOperator::StartsWith, 'John'),
            ],
        );

        $result = $this->compiler->compile($group);

        self::assertSame('name LIKE :f_0', $result['sql']);
        self::assertSame('John%', $result['params']['f_0']);
    }

    #[Test]
    public function compileEndsWithOperator(): void
    {
        $group = new FilterGroup(
            conditions: [
                new FilterCondition('email', FilterOperator::EndsWith, '@example.com'),
            ],
        );

        $result = $this->compiler->compile($group);

        self::assertSame('%@example.com', $result['params']['f_0']);
    }

    #[Test]
    public function compileNestedGroups(): void
    {
        $group = new FilterGroup(
            logic: FilterLogic::Or,
            groups: [
                new FilterGroup(
                    logic: FilterLogic::And,
                    conditions: [
                        new FilterCondition('name', FilterOperator::Equals, 'John'),
                        new FilterCondition('age', FilterOperator::GreaterThan, 18),
                    ],
                ),
                new FilterGroup(
                    logic: FilterLogic::And,
                    conditions: [
                        new FilterCondition('role', FilterOperator::Equals, 'admin'),
                    ],
                ),
            ],
        );

        $result = $this->compiler->compile($group);

        // (name = :f_0 AND age > :f_1) OR (role = :f_2)
        self::assertStringContainsString(' OR ', $result['sql']);
        self::assertStringContainsString('(', $result['sql']);
        self::assertCount(3, $result['params']);
    }

    #[Test]
    public function compileRejectsInvalidColumnNames(): void
    {
        $group = new FilterGroup(
            conditions: [
                new FilterCondition('DROP TABLE users; --', FilterOperator::Equals, 'x'),
            ],
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid column name');

        $this->compiler->compile($group);
    }

    #[Test]
    public function compileRejectsDottedColumnNames(): void
    {
        $group = new FilterGroup(
            conditions: [
                new FilterCondition('other_table.secret_column', FilterOperator::Equals, 'x'),
            ],
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid column name');

        $this->compiler->compile($group);
    }

    #[Test]
    public function compileRejectsTableQualifiedColumns(): void
    {
        $group = new FilterGroup(
            conditions: [
                new FilterCondition('users.password', FilterOperator::Contains, 'admin'),
            ],
        );

        $this->expectException(InvalidArgumentException::class);

        $this->compiler->compile($group);
    }

    #[Test]
    public function compileRejectsTooManyConditions(): void
    {
        $conditions = [];

        for ($i = 0; $i < 51; $i++) {
            $conditions[] = new FilterCondition("field_{$i}", FilterOperator::Equals, 'x');
        }

        $group = new FilterGroup(conditions: $conditions);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('exceeds maximum');

        $this->compiler->compile($group);
    }

    #[Test]
    public function filterGroupFromArrayDeserializesCorrectly(): void
    {
        $data = [
            'logic' => 'or',
            'conditions' => [
                ['field' => 'status', 'operator' => 'eq', 'value' => 'active'],
                ['field' => 'age', 'operator' => 'gte', 'value' => 18],
            ],
            'groups' => [],
        ];

        $group = FilterGroup::fromArray($data);

        self::assertSame(FilterLogic::Or, $group->logic);
        self::assertCount(2, $group->conditions);
        self::assertSame('status', $group->conditions[0]->field);
        self::assertSame(FilterOperator::Equals, $group->conditions[0]->operator);
    }

    #[Test]
    public function filterGroupToArrayRoundTrips(): void
    {
        $group = new FilterGroup(
            logic: FilterLogic::And,
            conditions: [
                new FilterCondition('name', FilterOperator::Contains, 'test'),
            ],
            groups: [
                new FilterGroup(
                    logic: FilterLogic::Or,
                    conditions: [
                        new FilterCondition('role', FilterOperator::Equals, 'admin'),
                    ],
                ),
            ],
        );

        $arr = $group->toArray();
        $restored = FilterGroup::fromArray($arr);

        self::assertSame($group->logic, $restored->logic);
        self::assertCount(1, $restored->conditions);
        self::assertCount(1, $restored->groups);
    }

    #[Test]
    public function filterOperatorRequiresValueDistinguishesNullOps(): void
    {
        self::assertFalse(FilterOperator::IsNull->requiresValue());
        self::assertFalse(FilterOperator::IsNotNull->requiresValue());
        self::assertTrue(FilterOperator::Equals->requiresValue());
        self::assertTrue(FilterOperator::Contains->requiresValue());
    }

    #[Test]
    public function filterOperatorIsMultiValueDistinguishesListOps(): void
    {
        self::assertTrue(FilterOperator::In->isMultiValue());
        self::assertTrue(FilterOperator::NotIn->isMultiValue());
        self::assertTrue(FilterOperator::Between->isMultiValue());
        self::assertFalse(FilterOperator::Equals->isMultiValue());
    }

    #[Test]
    public function filterGroupTotalConditionsCountsNested(): void
    {
        $group = new FilterGroup(
            conditions: [
                new FilterCondition('a', FilterOperator::Equals, 1),
            ],
            groups: [
                new FilterGroup(
                    conditions: [
                        new FilterCondition('b', FilterOperator::Equals, 2),
                        new FilterCondition('c', FilterOperator::Equals, 3),
                    ],
                ),
            ],
        );

        self::assertSame(3, $group->totalConditions());
    }
}
