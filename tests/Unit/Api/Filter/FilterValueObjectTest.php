<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api\Filter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Filter\FilterDefinition;
use Pulsar\Api\Filter\FilterExpression;
use Pulsar\Api\Filter\FilterOperator;
use Pulsar\Api\Filter\FilterValueType;

#[CoversClass(FilterOperator::class)]
#[CoversClass(FilterValueType::class)]
#[CoversClass(FilterDefinition::class)]
#[CoversClass(FilterExpression::class)]
final class FilterValueObjectTest extends TestCase
{
    // ── FilterOperator ──────────────────────────────────────────────────

    #[Test]
    public function filterOperatorHasNineCases(): void
    {
        self::assertCount(9, FilterOperator::cases());
    }

    #[Test]
    #[DataProvider('filterOperatorProvider')]
    public function filterOperatorBackedValues(FilterOperator $op, string $expected): void
    {
        self::assertSame($expected, $op->value);
    }

    /**
     * @return iterable<string, array{FilterOperator, string}>
     */
    public static function filterOperatorProvider(): iterable
    {
        yield 'Equal' => [FilterOperator::Equal, 'eq'];
        yield 'NotEqual' => [FilterOperator::NotEqual, 'neq'];
        yield 'GreaterThan' => [FilterOperator::GreaterThan, 'gt'];
        yield 'GreaterThanOrEqual' => [FilterOperator::GreaterThanOrEqual, 'gte'];
        yield 'LessThan' => [FilterOperator::LessThan, 'lt'];
        yield 'LessThanOrEqual' => [FilterOperator::LessThanOrEqual, 'lte'];
        yield 'In' => [FilterOperator::In, 'in'];
        yield 'Contains' => [FilterOperator::Contains, 'contains'];
        yield 'StartsWith' => [FilterOperator::StartsWith, 'starts_with'];
    }

    #[Test]
    public function filterOperatorFromBackedValue(): void
    {
        self::assertSame(FilterOperator::Equal, FilterOperator::from('eq'));
        self::assertSame(FilterOperator::StartsWith, FilterOperator::from('starts_with'));
    }

    // ── FilterValueType ─────────────────────────────────────────────────

    #[Test]
    public function filterValueTypeHasSevenCases(): void
    {
        self::assertCount(7, FilterValueType::cases());
    }

    #[Test]
    #[DataProvider('filterValueTypeProvider')]
    public function filterValueTypeBackedValues(FilterValueType $type, string $expected): void
    {
        self::assertSame($expected, $type->value);
    }

    /**
     * @return iterable<string, array{FilterValueType, string}>
     */
    public static function filterValueTypeProvider(): iterable
    {
        yield 'String' => [FilterValueType::String, 'string'];
        yield 'Integer' => [FilterValueType::Integer, 'integer'];
        yield 'Float' => [FilterValueType::Float, 'float'];
        yield 'Boolean' => [FilterValueType::Boolean, 'boolean'];
        yield 'Date' => [FilterValueType::Date, 'date'];
        yield 'DateTime' => [FilterValueType::DateTime, 'datetime'];
        yield 'Enum' => [FilterValueType::Enum, 'enum'];
    }

    #[Test]
    public function filterValueTypeFromBackedValue(): void
    {
        self::assertSame(FilterValueType::DateTime, FilterValueType::from('datetime'));
        self::assertSame(FilterValueType::Enum, FilterValueType::from('enum'));
    }

    // ── FilterDefinition ────────────────────────────────────────────────

    #[Test]
    public function filterDefinitionConstructionWithDefaults(): void
    {
        $def = new FilterDefinition(
            column: 'name',
            valueType: FilterValueType::String,
            allowedOperators: [FilterOperator::Equal, FilterOperator::Contains],
        );

        self::assertSame('name', $def->column);
        self::assertSame(FilterValueType::String, $def->valueType);
        self::assertCount(2, $def->allowedOperators);
        self::assertNull($def->guard);
        self::assertNull($def->enumClass);
    }

    #[Test]
    public function filterDefinitionConstructionWithGuardAndEnum(): void
    {
        $def = new FilterDefinition(
            column: 'status',
            valueType: FilterValueType::Enum,
            allowedOperators: [FilterOperator::Equal, FilterOperator::In],
            guard: 'admin:read',
            enumClass: FilterOperator::class,
        );

        self::assertSame('admin:read', $def->guard);
        self::assertSame(FilterOperator::class, $def->enumClass);
    }

    #[Test]
    public function filterDefinitionAllowsOperatorReturnsTrue(): void
    {
        $def = new FilterDefinition(
            column: 'age',
            valueType: FilterValueType::Integer,
            allowedOperators: [FilterOperator::GreaterThan, FilterOperator::LessThan, FilterOperator::Equal],
        );

        self::assertTrue($def->allowsOperator(FilterOperator::GreaterThan));
        self::assertTrue($def->allowsOperator(FilterOperator::Equal));
    }

    #[Test]
    public function filterDefinitionAllowsOperatorReturnsFalseForDisallowed(): void
    {
        $def = new FilterDefinition(
            column: 'age',
            valueType: FilterValueType::Integer,
            allowedOperators: [FilterOperator::Equal],
        );

        self::assertFalse($def->allowsOperator(FilterOperator::Contains));
        self::assertFalse($def->allowsOperator(FilterOperator::In));
    }

    #[Test]
    public function filterDefinitionRequiresAuthorizationWithGuard(): void
    {
        $def = new FilterDefinition(
            column: 'secret',
            valueType: FilterValueType::String,
            allowedOperators: [FilterOperator::Equal],
            guard: 'admin:sensitive',
        );

        self::assertTrue($def->requiresAuthorization());
    }

    #[Test]
    public function filterDefinitionDoesNotRequireAuthorizationWithoutGuard(): void
    {
        $def = new FilterDefinition(
            column: 'name',
            valueType: FilterValueType::String,
            allowedOperators: [FilterOperator::Equal],
        );

        self::assertFalse($def->requiresAuthorization());
    }

    #[Test]
    public function filterDefinitionWithEmptyOperators(): void
    {
        $def = new FilterDefinition(
            column: 'unused',
            valueType: FilterValueType::String,
            allowedOperators: [],
        );

        self::assertFalse($def->allowsOperator(FilterOperator::Equal));
    }

    // ── FilterExpression ────────────────────────────────────────────────

    #[Test]
    public function filterExpressionConstructor(): void
    {
        $expr = new FilterExpression(
            field: 'email',
            operator: FilterOperator::Contains,
            value: '@example.com',
        );

        self::assertSame('email', $expr->field);
        self::assertSame(FilterOperator::Contains, $expr->operator);
        self::assertSame('@example.com', $expr->value);
    }

    #[Test]
    public function filterExpressionCreateFactory(): void
    {
        $expr = FilterExpression::create('age', FilterOperator::GreaterThan, 18);

        self::assertSame('age', $expr->field);
        self::assertSame(FilterOperator::GreaterThan, $expr->operator);
        self::assertSame(18, $expr->value);
    }

    #[Test]
    public function filterExpressionCreateWithNullValue(): void
    {
        $expr = FilterExpression::create('deleted_at', FilterOperator::Equal, null);

        self::assertNull($expr->value);
        self::assertSame(FilterOperator::Equal, $expr->operator);
    }

    #[Test]
    public function filterExpressionCreateWithArrayValue(): void
    {
        $expr = FilterExpression::create('status', FilterOperator::In, ['active', 'pending']);

        self::assertSame(['active', 'pending'], $expr->value);
        self::assertSame(FilterOperator::In, $expr->operator);
    }
}
