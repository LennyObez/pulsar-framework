<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api\Filter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Exception\ApiException;
use Pulsar\Api\Filter\Filter;
use Pulsar\Api\Filter\FilterOperator;
use Pulsar\Api\Filter\FilterRegistry;
use Pulsar\Api\Filter\FilterValueType;

#[CoversClass(FilterRegistry::class)]
final class FilterRegistryTest extends TestCase
{
    #[Test]
    public function registerAndRetrieveDefinition(): void
    {
        $registry = new FilterRegistry();
        $registry->register('users', [
            'name' => Filter::string(),
            'age' => Filter::integer(),
        ]);

        $definition = $registry->get('users', 'name');

        self::assertSame(FilterValueType::String, $definition->valueType);
        self::assertSame('name', $definition->column);
    }

    #[Test]
    public function getUnknownResourceThrows400(): void
    {
        $registry = new FilterRegistry();

        $this->expectException(ApiException::class);
        $this->expectExceptionCode(400);
        $this->expectExceptionMessageMatches('/Unknown resource type "posts"/');

        $_ = $registry->get('posts', 'title');
    }

    #[Test]
    public function getUnknownFieldThrows400(): void
    {
        $registry = new FilterRegistry();
        $registry->register('users', [
            'name' => Filter::string(),
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionCode(400);
        $this->expectExceptionMessageMatches('/Filter field "password" is not registered/');

        $_ = $registry->get('users', 'password');
    }

    #[Test]
    public function hasReturnsTrueForRegisteredField(): void
    {
        $registry = new FilterRegistry();
        $registry->register('users', [
            'name' => Filter::string(),
        ]);

        self::assertTrue($registry->has('users', 'name'));
    }

    #[Test]
    public function hasReturnsFalseForUnregisteredField(): void
    {
        $registry = new FilterRegistry();
        $registry->register('users', [
            'name' => Filter::string(),
        ]);

        self::assertFalse($registry->has('users', 'password'));
    }

    #[Test]
    public function hasReturnsFalseForUnregisteredResource(): void
    {
        $registry = new FilterRegistry();

        self::assertFalse($registry->has('posts', 'title'));
    }

    #[Test]
    public function fieldsReturnsAllRegisteredFieldNames(): void
    {
        $registry = new FilterRegistry();
        $registry->register('users', [
            'name' => Filter::string(),
            'age' => Filter::integer(),
            'active' => Filter::boolean(),
        ]);

        self::assertSame(['name', 'age', 'active'], $registry->fields('users'));
    }

    #[Test]
    public function fieldsReturnsEmptyForUnknownResource(): void
    {
        $registry = new FilterRegistry();

        self::assertSame([], $registry->fields('unknown'));
    }

    #[Test]
    public function customColumnMappingIsPreserved(): void
    {
        $registry = new FilterRegistry();
        $registry->register('users', [
            'created_after' => Filter::date('created_at'),
        ]);

        $definition = $registry->get('users', 'created_after');

        self::assertSame('created_at', $definition->column);
        self::assertSame(FilterValueType::Date, $definition->valueType);
    }

    #[Test]
    public function guardedFilterPreservesGuardRole(): void
    {
        $registry = new FilterRegistry();
        $registry->register('users', [
            'internal' => Filter::string()->guard('admin'),
        ]);

        $definition = $registry->get('users', 'internal');

        self::assertSame('admin', $definition->guard);
        self::assertTrue($definition->requiresAuthorization());
    }

    #[Test]
    public function definitionAllowsRegisteredOperators(): void
    {
        $registry = new FilterRegistry();
        $registry->register('users', [
            'active' => Filter::boolean(),
        ]);

        $definition = $registry->get('users', 'active');

        self::assertTrue($definition->allowsOperator(FilterOperator::Equal));
        self::assertFalse($definition->allowsOperator(FilterOperator::GreaterThan));
    }

    #[Test]
    public function enumFilterPreservesEnumClass(): void
    {
        $registry = new FilterRegistry();
        $registry->register('users', [
            'role' => Filter::enum(TestUserRole::class),
        ]);

        $definition = $registry->get('users', 'role');

        self::assertSame(FilterValueType::Enum, $definition->valueType);
        self::assertSame(TestUserRole::class, $definition->enumClass);
    }
}
