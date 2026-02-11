<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api\Filter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Filter\Filter;
use Pulsar\Api\Filter\FilterDefinition;
use Pulsar\Api\Filter\FilterOperator;
use Pulsar\Api\Filter\FilterValueType;

#[CoversClass(Filter::class)]
final class FilterTest extends TestCase
{
    // --- String filter ---

    #[Test]
    public function stringFilterHasCorrectValueType(): void
    {
        $definition = Filter::string()->build('name');

        self::assertSame(FilterValueType::String, $definition->valueType);
    }

    #[Test]
    public function stringFilterDefaultColumnMatchesFieldName(): void
    {
        $definition = Filter::string()->build('name');

        self::assertSame('name', $definition->column);
    }

    #[Test]
    public function stringFilterCustomColumnOverridesFieldName(): void
    {
        $definition = Filter::string('display_name')->build('name');

        self::assertSame('display_name', $definition->column);
    }

    #[Test]
    public function stringFilterAllowedOperators(): void
    {
        $definition = Filter::string()->build('name');

        self::assertTrue($definition->allowsOperator(FilterOperator::Equal));
        self::assertTrue($definition->allowsOperator(FilterOperator::NotEqual));
        self::assertTrue($definition->allowsOperator(FilterOperator::Contains));
        self::assertTrue($definition->allowsOperator(FilterOperator::StartsWith));
        self::assertTrue($definition->allowsOperator(FilterOperator::In));
        self::assertFalse($definition->allowsOperator(FilterOperator::GreaterThan));
        self::assertFalse($definition->allowsOperator(FilterOperator::LessThan));
    }

    // --- Integer filter ---

    #[Test]
    public function integerFilterHasCorrectValueType(): void
    {
        $definition = Filter::integer()->build('age');

        self::assertSame(FilterValueType::Integer, $definition->valueType);
    }

    #[Test]
    public function integerFilterAllowedOperators(): void
    {
        $definition = Filter::integer()->build('age');

        self::assertTrue($definition->allowsOperator(FilterOperator::Equal));
        self::assertTrue($definition->allowsOperator(FilterOperator::NotEqual));
        self::assertTrue($definition->allowsOperator(FilterOperator::GreaterThan));
        self::assertTrue($definition->allowsOperator(FilterOperator::GreaterThanOrEqual));
        self::assertTrue($definition->allowsOperator(FilterOperator::LessThan));
        self::assertTrue($definition->allowsOperator(FilterOperator::LessThanOrEqual));
        self::assertTrue($definition->allowsOperator(FilterOperator::In));
        self::assertFalse($definition->allowsOperator(FilterOperator::Contains));
        self::assertFalse($definition->allowsOperator(FilterOperator::StartsWith));
    }

    #[Test]
    public function integerFilterCustomColumn(): void
    {
        $definition = Filter::integer('user_age')->build('age');

        self::assertSame('user_age', $definition->column);
    }

    // --- Float filter ---

    #[Test]
    public function floatFilterHasCorrectValueType(): void
    {
        $definition = Filter::float()->build('price');

        self::assertSame(FilterValueType::Float, $definition->valueType);
    }

    #[Test]
    public function floatFilterAllowedOperators(): void
    {
        $definition = Filter::float()->build('price');

        self::assertTrue($definition->allowsOperator(FilterOperator::Equal));
        self::assertTrue($definition->allowsOperator(FilterOperator::NotEqual));
        self::assertTrue($definition->allowsOperator(FilterOperator::GreaterThan));
        self::assertTrue($definition->allowsOperator(FilterOperator::GreaterThanOrEqual));
        self::assertTrue($definition->allowsOperator(FilterOperator::LessThan));
        self::assertTrue($definition->allowsOperator(FilterOperator::LessThanOrEqual));
        self::assertFalse($definition->allowsOperator(FilterOperator::In));
        self::assertFalse($definition->allowsOperator(FilterOperator::Contains));
    }

    // --- Boolean filter ---

    #[Test]
    public function booleanFilterHasCorrectValueType(): void
    {
        $definition = Filter::boolean()->build('active');

        self::assertSame(FilterValueType::Boolean, $definition->valueType);
    }

    #[Test]
    public function booleanFilterOnlyAllowsEqual(): void
    {
        $definition = Filter::boolean()->build('active');

        self::assertTrue($definition->allowsOperator(FilterOperator::Equal));
        self::assertFalse($definition->allowsOperator(FilterOperator::NotEqual));
        self::assertFalse($definition->allowsOperator(FilterOperator::In));
        self::assertFalse($definition->allowsOperator(FilterOperator::GreaterThan));
    }

    #[Test]
    public function booleanFilterCustomColumn(): void
    {
        $definition = Filter::boolean('is_active')->build('active');

        self::assertSame('is_active', $definition->column);
    }

    // --- Date filter ---

    #[Test]
    public function dateFilterHasCorrectValueType(): void
    {
        $definition = Filter::date('created_at')->build('created_after');

        self::assertSame(FilterValueType::Date, $definition->valueType);
    }

    #[Test]
    public function dateFilterUsesProvidedColumn(): void
    {
        $definition = Filter::date('created_at')->build('created_after');

        self::assertSame('created_at', $definition->column);
    }

    #[Test]
    public function dateFilterAllowedOperators(): void
    {
        $definition = Filter::date('updated_at')->build('updated');

        self::assertTrue($definition->allowsOperator(FilterOperator::Equal));
        self::assertTrue($definition->allowsOperator(FilterOperator::NotEqual));
        self::assertTrue($definition->allowsOperator(FilterOperator::GreaterThan));
        self::assertTrue($definition->allowsOperator(FilterOperator::GreaterThanOrEqual));
        self::assertTrue($definition->allowsOperator(FilterOperator::LessThan));
        self::assertTrue($definition->allowsOperator(FilterOperator::LessThanOrEqual));
        self::assertFalse($definition->allowsOperator(FilterOperator::In));
        self::assertFalse($definition->allowsOperator(FilterOperator::Contains));
    }

    // --- Enum filter ---

    #[Test]
    public function enumFilterHasCorrectValueType(): void
    {
        $definition = Filter::enum(TestUserRole::class)->build('role');

        self::assertSame(FilterValueType::Enum, $definition->valueType);
    }

    #[Test]
    public function enumFilterPreservesEnumClass(): void
    {
        $definition = Filter::enum(TestUserRole::class)->build('role');

        self::assertSame(TestUserRole::class, $definition->enumClass);
    }

    #[Test]
    public function enumFilterAllowedOperators(): void
    {
        $definition = Filter::enum(TestUserRole::class)->build('role');

        self::assertTrue($definition->allowsOperator(FilterOperator::Equal));
        self::assertTrue($definition->allowsOperator(FilterOperator::NotEqual));
        self::assertTrue($definition->allowsOperator(FilterOperator::In));
        self::assertFalse($definition->allowsOperator(FilterOperator::GreaterThan));
        self::assertFalse($definition->allowsOperator(FilterOperator::Contains));
    }

    #[Test]
    public function enumFilterCustomColumn(): void
    {
        $definition = Filter::enum(TestUserRole::class, 'user_role')->build('role');

        self::assertSame('user_role', $definition->column);
    }

    #[Test]
    public function enumFilterDefaultColumnUsesFieldName(): void
    {
        $definition = Filter::enum(TestUserRole::class)->build('role');

        self::assertSame('role', $definition->column);
    }

    // --- Guard ---

    #[Test]
    public function guardReturnsNewInstanceWithRole(): void
    {
        $original = Filter::string();
        $guarded = $original->guard('admin');

        $originalDef = $original->build('field');
        $guardedDef = $guarded->build('field');

        self::assertNull($originalDef->guard);
        self::assertFalse($originalDef->requiresAuthorization());
        self::assertSame('admin', $guardedDef->guard);
        self::assertTrue($guardedDef->requiresAuthorization());
    }

    #[Test]
    public function guardPreservesFilterTypeAndOperators(): void
    {
        $guarded = Filter::integer('custom_col')->guard('editor');
        $definition = $guarded->build('count');

        self::assertSame(FilterValueType::Integer, $definition->valueType);
        self::assertSame('custom_col', $definition->column);
        self::assertSame('editor', $definition->guard);
        self::assertTrue($definition->allowsOperator(FilterOperator::GreaterThan));
    }

    #[Test]
    public function guardCanBeChainedOnEnumFilter(): void
    {
        $definition = Filter::enum(TestUserRole::class)->guard('superadmin')->build('role');

        self::assertSame(FilterValueType::Enum, $definition->valueType);
        self::assertSame(TestUserRole::class, $definition->enumClass);
        self::assertSame('superadmin', $definition->guard);
    }

    // --- build() ---

    #[Test]
    public function buildReturnsFilterDefinitionInstance(): void
    {
        $definition = Filter::string()->build('name');

        self::assertInstanceOf(FilterDefinition::class, $definition);
    }

    #[Test]
    public function buildWithNoGuardSetsGuardToNull(): void
    {
        $definition = Filter::boolean()->build('active');

        self::assertNull($definition->guard);
        self::assertFalse($definition->requiresAuthorization());
    }

    #[Test]
    public function buildWithNoEnumClassSetsEnumClassToNull(): void
    {
        $definition = Filter::string()->build('name');

        self::assertNull($definition->enumClass);
    }

    // --- Data provider for factory methods ---

    #[Test]
    #[DataProvider('filterFactoryProvider')]
    public function factoryMethodsProduceCorrectValueType(Filter $filter, FilterValueType $expectedType): void
    {
        $definition = $filter->build('test');

        self::assertSame($expectedType, $definition->valueType);
    }

    /**
     * @return iterable<string, array{Filter, FilterValueType}>
     */
    public static function filterFactoryProvider(): iterable
    {
        yield 'string' => [Filter::string(), FilterValueType::String];
        yield 'integer' => [Filter::integer(), FilterValueType::Integer];
        yield 'float' => [Filter::float(), FilterValueType::Float];
        yield 'boolean' => [Filter::boolean(), FilterValueType::Boolean];
        yield 'date' => [Filter::date('col'), FilterValueType::Date];
        yield 'enum' => [Filter::enum(TestUserRole::class), FilterValueType::Enum];
    }
}
