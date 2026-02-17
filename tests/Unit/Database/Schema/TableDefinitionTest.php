<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Schema\SchemaColumn;
use Pulsar\Database\Schema\SchemaColumnType;
use Pulsar\Database\Schema\SchemaForeignKey;
use Pulsar\Database\Schema\SchemaIndex;
use Pulsar\Database\Schema\SchemaReferentialAction;
use Pulsar\Database\Schema\TableDefinition;
use ReflectionClass;

#[CoversClass(TableDefinition::class)]
final class TableDefinitionTest extends TestCase
{
    #[Test]
    public function constructsWithColumnsOnly(): void
    {
        $columns = [
            new SchemaColumn(name: 'id', type: SchemaColumnType::Integer, primaryKey: true, autoIncrement: true),
            new SchemaColumn(name: 'name', type: SchemaColumnType::String, length: 255),
        ];

        $table = new TableDefinition(name: 'users', columns: $columns);

        self::assertSame('users', $table->name);
        self::assertCount(2, $table->columns);
        self::assertSame([], $table->indexes);
        self::assertSame([], $table->foreignKeys);
    }

    #[Test]
    public function constructsWithIndexes(): void
    {
        $columns = [
            new SchemaColumn(name: 'id', type: SchemaColumnType::Integer, primaryKey: true),
            new SchemaColumn(name: 'email', type: SchemaColumnType::String, length: 255),
        ];

        $indexes = [
            new SchemaIndex(name: 'uq_users_email', columns: ['email'], unique: true),
        ];

        $table = new TableDefinition(name: 'users', columns: $columns, indexes: $indexes);

        self::assertCount(1, $table->indexes);
        self::assertSame('uq_users_email', $table->indexes[0]->name);
        self::assertTrue($table->indexes[0]->unique);
    }

    #[Test]
    public function constructsWithForeignKeys(): void
    {
        $columns = [
            new SchemaColumn(name: 'id', type: SchemaColumnType::Integer, primaryKey: true),
            new SchemaColumn(name: 'user_id', type: SchemaColumnType::Integer),
            new SchemaColumn(name: 'title', type: SchemaColumnType::String, length: 200),
        ];

        $foreignKeys = [
            new SchemaForeignKey(
                name: 'fk_posts_user_id',
                columns: ['user_id'],
                referencedTable: 'users',
                referencedColumns: ['id'],
                onDelete: SchemaReferentialAction::Cascade,
            ),
        ];

        $table = new TableDefinition(
            name: 'posts',
            columns: $columns,
            foreignKeys: $foreignKeys,
        );

        self::assertSame('posts', $table->name);
        self::assertCount(3, $table->columns);
        self::assertCount(1, $table->foreignKeys);
        self::assertSame('fk_posts_user_id', $table->foreignKeys[0]->name);
    }

    #[Test]
    public function constructsCompleteTableDefinition(): void
    {
        $columns = [
            new SchemaColumn(name: 'id', type: SchemaColumnType::BigInt, primaryKey: true, autoIncrement: true, unsigned: true),
            new SchemaColumn(name: 'user_id', type: SchemaColumnType::BigInt, unsigned: true),
            new SchemaColumn(name: 'product_id', type: SchemaColumnType::BigInt, unsigned: true),
            new SchemaColumn(name: 'quantity', type: SchemaColumnType::Integer, unsigned: true, default: 1, hasDefault: true),
            new SchemaColumn(name: 'total', type: SchemaColumnType::Decimal, precision: 12, scale: 2),
        ];

        $indexes = [
            new SchemaIndex(name: 'idx_order_items_user', columns: ['user_id']),
            new SchemaIndex(name: 'uq_order_items_user_product', columns: ['user_id', 'product_id'], unique: true),
        ];

        $foreignKeys = [
            new SchemaForeignKey(
                name: 'fk_items_user',
                columns: ['user_id'],
                referencedTable: 'users',
                referencedColumns: ['id'],
                onDelete: SchemaReferentialAction::Cascade,
            ),
            new SchemaForeignKey(
                name: 'fk_items_product',
                columns: ['product_id'],
                referencedTable: 'products',
                referencedColumns: ['id'],
                onDelete: SchemaReferentialAction::Restrict,
            ),
        ];

        $table = new TableDefinition(
            name: 'order_items',
            columns: $columns,
            indexes: $indexes,
            foreignKeys: $foreignKeys,
        );

        self::assertSame('order_items', $table->name);
        self::assertCount(5, $table->columns);
        self::assertCount(2, $table->indexes);
        self::assertCount(2, $table->foreignKeys);
    }

    #[Test]
    public function isReadonly(): void
    {
        $table = new TableDefinition(
            name: 'test',
            columns: [new SchemaColumn(name: 'id', type: SchemaColumnType::Integer)],
        );

        $reflection = new ReflectionClass($table);
        self::assertTrue($reflection->isReadOnly());
    }
}
