<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Schema\SchemaIndex;
use ReflectionClass;

#[CoversClass(SchemaIndex::class)]
final class SchemaIndexTest extends TestCase
{
    #[Test]
    public function constructsNonUniqueIndexByDefault(): void
    {
        $index = new SchemaIndex(name: 'idx_users_email', columns: ['email']);

        self::assertSame('idx_users_email', $index->name);
        self::assertSame(['email'], $index->columns);
        self::assertFalse($index->unique);
    }

    #[Test]
    public function constructsUniqueIndex(): void
    {
        $index = new SchemaIndex(
            name: 'uq_users_email',
            columns: ['email'],
            unique: true,
        );

        self::assertTrue($index->unique);
    }

    #[Test]
    public function constructsCompositeIndex(): void
    {
        $index = new SchemaIndex(
            name: 'idx_orders_user_date',
            columns: ['user_id', 'created_at'],
        );

        self::assertSame(['user_id', 'created_at'], $index->columns);
        self::assertCount(2, $index->columns);
    }

    #[Test]
    public function constructsCompositeUniqueIndex(): void
    {
        $index = new SchemaIndex(
            name: 'uq_tenant_slug',
            columns: ['tenant_id', 'slug'],
            unique: true,
        );

        self::assertSame(['tenant_id', 'slug'], $index->columns);
        self::assertTrue($index->unique);
    }

    #[Test]
    public function isReadonly(): void
    {
        $index = new SchemaIndex(name: 'idx_test', columns: ['col']);

        $reflection = new ReflectionClass($index);
        self::assertTrue($reflection->isReadOnly());
    }
}
