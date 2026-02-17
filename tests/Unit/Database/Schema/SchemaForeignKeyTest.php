<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Schema\SchemaForeignKey;
use Pulsar\Database\Schema\SchemaReferentialAction;
use ReflectionClass;

#[CoversClass(SchemaForeignKey::class)]
final class SchemaForeignKeyTest extends TestCase
{
    #[Test]
    public function constructsWithDefaultActions(): void
    {
        $fk = new SchemaForeignKey(
            name: 'fk_orders_user_id',
            columns: ['user_id'],
            referencedTable: 'users',
            referencedColumns: ['id'],
        );

        self::assertSame('fk_orders_user_id', $fk->name);
        self::assertSame(['user_id'], $fk->columns);
        self::assertSame('users', $fk->referencedTable);
        self::assertSame(['id'], $fk->referencedColumns);
        self::assertSame(SchemaReferentialAction::Restrict, $fk->onDelete);
        self::assertSame(SchemaReferentialAction::Restrict, $fk->onUpdate);
    }

    #[Test]
    public function constructsWithCascadeActions(): void
    {
        $fk = new SchemaForeignKey(
            name: 'fk_posts_author',
            columns: ['author_id'],
            referencedTable: 'authors',
            referencedColumns: ['id'],
            onDelete: SchemaReferentialAction::Cascade,
            onUpdate: SchemaReferentialAction::Cascade,
        );

        self::assertSame(SchemaReferentialAction::Cascade, $fk->onDelete);
        self::assertSame(SchemaReferentialAction::Cascade, $fk->onUpdate);
    }

    #[Test]
    public function constructsWithSetNullOnDelete(): void
    {
        $fk = new SchemaForeignKey(
            name: 'fk_comments_post',
            columns: ['post_id'],
            referencedTable: 'posts',
            referencedColumns: ['id'],
            onDelete: SchemaReferentialAction::SetNull,
            onUpdate: SchemaReferentialAction::NoAction,
        );

        self::assertSame(SchemaReferentialAction::SetNull, $fk->onDelete);
        self::assertSame(SchemaReferentialAction::NoAction, $fk->onUpdate);
    }

    #[Test]
    public function constructsWithCompositeColumns(): void
    {
        $fk = new SchemaForeignKey(
            name: 'fk_order_items_composite',
            columns: ['order_id', 'product_id'],
            referencedTable: 'order_products',
            referencedColumns: ['order_id', 'product_id'],
        );

        self::assertSame(['order_id', 'product_id'], $fk->columns);
        self::assertSame(['order_id', 'product_id'], $fk->referencedColumns);
    }

    #[Test]
    public function isReadonly(): void
    {
        $fk = new SchemaForeignKey(
            name: 'fk_test',
            columns: ['col'],
            referencedTable: 'ref',
            referencedColumns: ['id'],
        );

        $reflection = new ReflectionClass($fk);
        self::assertTrue($reflection->isReadOnly());
    }
}
