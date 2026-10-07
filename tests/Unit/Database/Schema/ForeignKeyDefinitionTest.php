<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Schema\Blueprint;
use Pulsar\Database\Schema\ColumnBuilder;
use Pulsar\Database\Schema\ForeignIdBuilder;
use Pulsar\Database\Schema\SchemaColumnType;
use Pulsar\Database\Schema\SchemaReferentialAction;

#[CoversClass(ForeignIdBuilder::class)]
#[CoversClass(Blueprint::class)]
#[CoversClass(ColumnBuilder::class)]
final class ForeignKeyDefinitionTest extends TestCase
{
    #[Test]
    public function referencesReturnsSelfForChaining(): void
    {
        $blueprint = new Blueprint('posts');
        $builder = $blueprint->foreignId('user_id');
        $result = $builder->references('id');

        self::assertSame($builder, $result);
    }

    #[Test]
    public function onReturnsSelfForChaining(): void
    {
        $blueprint = new Blueprint('posts');
        $builder = $blueprint->foreignId('user_id');
        $result = $builder->references('id')->on('users');

        self::assertSame($builder, $result);
    }

    #[Test]
    public function onDeleteReturnsSelfForChaining(): void
    {
        $blueprint = new Blueprint('posts');
        $builder = $blueprint->foreignId('user_id');
        $result = $builder->onDelete(SchemaReferentialAction::Cascade);

        self::assertSame($builder, $result);
    }

    #[Test]
    public function onUpdateReturnsSelfForChaining(): void
    {
        $blueprint = new Blueprint('posts');
        $builder = $blueprint->foreignId('user_id');
        $result = $builder->onUpdate(SchemaReferentialAction::SetNull);

        self::assertSame($builder, $result);
    }

    #[Test]
    public function foreignIdCreatesBigIntUnsignedColumn(): void
    {
        $blueprint = new Blueprint('posts');
        $blueprint->foreignId('user_id')->references('id')->on('users');
        $def = $blueprint->toDefinition();

        self::assertCount(1, $def->columns);
        $col = $def->columns[0];
        self::assertSame('user_id', $col->name);
        self::assertSame(SchemaColumnType::BigInt, $col->type);
        self::assertTrue($col->unsigned);
    }

    #[Test]
    public function foreignIdCreatesForeignKeyConstraint(): void
    {
        $blueprint = new Blueprint('posts');
        $blueprint->foreignId('user_id')->references('id')->on('users');
        $def = $blueprint->toDefinition();

        self::assertCount(1, $def->foreignKeys);
        $fk = $def->foreignKeys[0];
        self::assertSame('fk_posts_user_id', $fk->name);
        self::assertSame(['user_id'], $fk->columns);
        self::assertSame('users', $fk->referencedTable);
        self::assertSame(['id'], $fk->referencedColumns);
    }

    #[Test]
    public function onDeleteSetsDeleteAction(): void
    {
        $blueprint = new Blueprint('comments');
        $blueprint->foreignId('post_id')
            ->onDelete(SchemaReferentialAction::Cascade)
            ->references('id')
            ->on('posts');
        $def = $blueprint->toDefinition();

        self::assertSame(SchemaReferentialAction::Cascade, $def->foreignKeys[0]->onDelete);
    }

    #[Test]
    public function onUpdateSetsUpdateAction(): void
    {
        $blueprint = new Blueprint('comments');
        $blueprint->foreignId('post_id')
            ->onUpdate(SchemaReferentialAction::Cascade)
            ->references('id')
            ->on('posts');
        $def = $blueprint->toDefinition();

        self::assertSame(SchemaReferentialAction::Cascade, $def->foreignKeys[0]->onUpdate);
    }

    #[Test]
    public function defaultReferentialActionsAreRestrict(): void
    {
        $blueprint = new Blueprint('posts');
        $blueprint->foreignId('user_id')->references('id')->on('users');
        $def = $blueprint->toDefinition();

        self::assertSame(SchemaReferentialAction::Restrict, $def->foreignKeys[0]->onDelete);
        self::assertSame(SchemaReferentialAction::Restrict, $def->foreignKeys[0]->onUpdate);
    }

    #[Test]
    public function nullableForeignKey(): void
    {
        $blueprint = new Blueprint('posts');
        $blueprint->foreignId('category_id')
            ->nullable()
            ->references('id')
            ->on('categories');
        $def = $blueprint->toDefinition();

        self::assertTrue($def->columns[0]->nullable);
    }

    #[Test]
    public function defaultValueOnForeignKey(): void
    {
        $blueprint = new Blueprint('posts');
        $blueprint->foreignId('category_id')
            ->nullable()
            ->default(null)
            ->references('id')
            ->on('categories');
        $def = $blueprint->toDefinition();

        self::assertTrue($def->columns[0]->hasDefault);
        self::assertNull($def->columns[0]->default);
    }

    #[Test]
    public function setNullOnDeleteWithNullableColumn(): void
    {
        $blueprint = new Blueprint('posts');
        $blueprint->foreignId('author_id')
            ->nullable()
            ->onDelete(SchemaReferentialAction::SetNull)
            ->onUpdate(SchemaReferentialAction::NoAction)
            ->references('id')
            ->on('authors');
        $def = $blueprint->toDefinition();

        self::assertTrue($def->columns[0]->nullable);
        self::assertSame(SchemaReferentialAction::SetNull, $def->foreignKeys[0]->onDelete);
        self::assertSame(SchemaReferentialAction::NoAction, $def->foreignKeys[0]->onUpdate);
    }

    #[Test]
    public function referencesDefaultsToIdColumn(): void
    {
        $blueprint = new Blueprint('posts');
        // Call on() without calling references() first -- should default to 'id'
        $blueprint->foreignId('user_id')->on('users');
        $def = $blueprint->toDefinition();

        self::assertSame(['id'], $def->foreignKeys[0]->referencedColumns);
    }

    #[Test]
    public function multipleForeignKeysOnSameTable(): void
    {
        $blueprint = new Blueprint('order_items');
        $blueprint->id();
        $blueprint->foreignId('order_id')->references('id')->on('orders');
        $blueprint->foreignId('product_id')->references('id')->on('products');
        $def = $blueprint->toDefinition();

        self::assertCount(3, $def->columns); // id + order_id + product_id
        self::assertCount(2, $def->foreignKeys);
        self::assertSame('fk_order_items_order_id', $def->foreignKeys[0]->name);
        self::assertSame('fk_order_items_product_id', $def->foreignKeys[1]->name);
    }

    #[Test]
    public function foreignKeyNameIncludesTableAndColumn(): void
    {
        $blueprint = new Blueprint('payments');
        $blueprint->foreignId('invoice_id')->references('id')->on('invoices');
        $def = $blueprint->toDefinition();

        self::assertSame('fk_payments_invoice_id', $def->foreignKeys[0]->name);
    }
}
