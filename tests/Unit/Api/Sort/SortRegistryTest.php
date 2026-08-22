<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api\Sort;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Exception\ApiException;
use Pulsar\Api\Sort\SortDefinition;
use Pulsar\Api\Sort\SortRegistry;

#[CoversClass(SortRegistry::class)]
final class SortRegistryTest extends TestCase
{
    // --- register and get ---

    #[Test]
    public function registerAndRetrieveDefinition(): void
    {
        $registry = new SortRegistry();
        $definition = new SortDefinition(column: 'created_at');

        $registry->register('posts', ['created' => $definition]);

        self::assertSame($definition, $registry->get('posts', 'created'));
    }

    #[Test]
    public function registerMultipleFieldsForResource(): void
    {
        $registry = new SortRegistry();
        $registry->register('posts', [
            'title' => new SortDefinition(column: 'title'),
            'created' => new SortDefinition(column: 'created_at'),
            'updated' => new SortDefinition(column: 'updated_at', guard: 'editor'),
        ]);

        self::assertSame('title', $registry->get('posts', 'title')->column);
        self::assertSame('created_at', $registry->get('posts', 'created')->column);
        self::assertSame('editor', $registry->get('posts', 'updated')->guard);
    }

    #[Test]
    public function registerOverwritesPreviousDefinitions(): void
    {
        $registry = new SortRegistry();
        $registry->register('posts', [
            'title' => new SortDefinition(column: 'title'),
        ]);
        $registry->register('posts', [
            'name' => new SortDefinition(column: 'name'),
        ]);

        self::assertTrue($registry->has('posts', 'name'));
        self::assertFalse($registry->has('posts', 'title'));
    }

    // --- get() error cases ---

    #[Test]
    public function getUnknownResourceThrows(): void
    {
        $registry = new SortRegistry();

        $this->expectException(ApiException::class);
        $this->expectExceptionCode(400);
        $this->expectExceptionMessageMatches('/Unknown resource type "unknown"/');

        $_ = $registry->get('unknown', 'field');
    }

    #[Test]
    public function getUnknownFieldThrows(): void
    {
        $registry = new SortRegistry();
        $registry->register('posts', [
            'title' => new SortDefinition(column: 'title'),
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionCode(400);
        $this->expectExceptionMessageMatches('/Sort field "unknown"/');

        $_ = $registry->get('posts', 'unknown');
    }

    // --- has() ---

    #[Test]
    public function hasReturnsTrueForRegisteredField(): void
    {
        $registry = new SortRegistry();
        $registry->register('users', [
            'name' => new SortDefinition(column: 'name'),
        ]);

        self::assertTrue($registry->has('users', 'name'));
    }

    #[Test]
    public function hasReturnsFalseForUnregisteredField(): void
    {
        $registry = new SortRegistry();
        $registry->register('users', [
            'name' => new SortDefinition(column: 'name'),
        ]);

        self::assertFalse($registry->has('users', 'email'));
    }

    #[Test]
    public function hasReturnsFalseForUnregisteredResource(): void
    {
        $registry = new SortRegistry();

        self::assertFalse($registry->has('products', 'price'));
    }

    // --- fields() ---

    #[Test]
    public function fieldsReturnsAllRegisteredFieldNames(): void
    {
        $registry = new SortRegistry();
        $registry->register('posts', [
            'title' => new SortDefinition(column: 'title'),
            'created' => new SortDefinition(column: 'created_at'),
            'score' => new SortDefinition(column: 'relevance_score', guard: 'admin'),
        ]);

        self::assertSame(['title', 'created', 'score'], $registry->fields('posts'));
    }

    #[Test]
    public function fieldsReturnsEmptyForUnknownResource(): void
    {
        $registry = new SortRegistry();

        self::assertSame([], $registry->fields('unknown'));
    }

    #[Test]
    public function fieldsReturnsEmptyForEmptyRegistration(): void
    {
        $registry = new SortRegistry();
        $registry->register('empty', []);

        self::assertSame([], $registry->fields('empty'));
    }

    // --- Multiple resource types ---

    #[Test]
    public function multipleResourceTypesAreIndependent(): void
    {
        $registry = new SortRegistry();
        $registry->register('posts', [
            'title' => new SortDefinition(column: 'title'),
        ]);
        $registry->register('comments', [
            'date' => new SortDefinition(column: 'created_at'),
        ]);

        self::assertTrue($registry->has('posts', 'title'));
        self::assertFalse($registry->has('posts', 'date'));
        self::assertTrue($registry->has('comments', 'date'));
        self::assertFalse($registry->has('comments', 'title'));
    }

    // --- SortDefinition authorization ---

    #[Test]
    public function sortDefinitionWithGuardRequiresAuthorization(): void
    {
        $definition = new SortDefinition(column: 'secret', guard: 'admin');

        self::assertTrue($definition->requiresAuthorization());
        self::assertSame('admin', $definition->guard);
    }

    #[Test]
    public function sortDefinitionWithoutGuardDoesNotRequireAuthorization(): void
    {
        $definition = new SortDefinition(column: 'name');

        self::assertFalse($definition->requiresAuthorization());
        self::assertNull($definition->guard);
    }
}
