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
use Pulsar\Database\Schema\SchemaDefaultExpression;
use Pulsar\Database\Schema\SchemaReferentialAction;

#[CoversClass(Blueprint::class)]
#[CoversClass(ColumnBuilder::class)]
#[CoversClass(ForeignIdBuilder::class)]
final class BlueprintTest extends TestCase
{
    #[Test]
    public function idAddsAutoIncrementBigintPrimaryKey(): void
    {
        $blueprint = new Blueprint('users');
        $blueprint->id();
        $def = $blueprint->toDefinition();

        self::assertCount(1, $def->columns);
        $col = $def->columns[0];
        self::assertSame('id', $col->name);
        self::assertSame(SchemaColumnType::BigInt, $col->type);
        self::assertTrue($col->primaryKey);
        self::assertTrue($col->autoIncrement);
    }

    #[Test]
    public function idAcceptsCustomColumnName(): void
    {
        $blueprint = new Blueprint('users');
        $blueprint->id('user_id');
        $def = $blueprint->toDefinition();

        self::assertSame('user_id', $def->columns[0]->name);
    }

    #[Test]
    public function stringAddsVarcharColumn(): void
    {
        $blueprint = new Blueprint('users');
        $blueprint->string('name', 100);
        $def = $blueprint->toDefinition();

        $col = $def->columns[0];
        self::assertSame('name', $col->name);
        self::assertSame(SchemaColumnType::String, $col->type);
        self::assertSame(100, $col->length);
        self::assertFalse($col->nullable);
    }

    #[Test]
    public function stringDefaultsTo255Length(): void
    {
        $blueprint = new Blueprint('users');
        $blueprint->string('email');
        $def = $blueprint->toDefinition();

        self::assertSame(255, $def->columns[0]->length);
    }

    #[Test]
    public function textAddsTextColumn(): void
    {
        $blueprint = new Blueprint('posts');
        $blueprint->text('body');
        $def = $blueprint->toDefinition();

        self::assertSame(SchemaColumnType::Text, $def->columns[0]->type);
    }

    #[Test]
    public function integerAddsIntegerColumn(): void
    {
        $blueprint = new Blueprint('stats');
        $blueprint->integer('count');
        $def = $blueprint->toDefinition();

        self::assertSame(SchemaColumnType::Integer, $def->columns[0]->type);
    }

    #[Test]
    public function bigIntegerAddsBigIntColumn(): void
    {
        $blueprint = new Blueprint('stats');
        $blueprint->bigInteger('views');
        $def = $blueprint->toDefinition();

        self::assertSame(SchemaColumnType::BigInt, $def->columns[0]->type);
    }

    #[Test]
    public function smallIntegerAddsSmallIntColumn(): void
    {
        $blueprint = new Blueprint('stats');
        $blueprint->smallInteger('priority');
        $def = $blueprint->toDefinition();

        self::assertSame(SchemaColumnType::SmallInt, $def->columns[0]->type);
    }

    #[Test]
    public function booleanAddsBooleanColumn(): void
    {
        $blueprint = new Blueprint('users');
        $blueprint->boolean('active');
        $def = $blueprint->toDefinition();

        self::assertSame(SchemaColumnType::Boolean, $def->columns[0]->type);
    }

    #[Test]
    public function timestampAddsDateTimeColumn(): void
    {
        $blueprint = new Blueprint('events');
        $blueprint->timestamp('fired_at');
        $def = $blueprint->toDefinition();

        self::assertSame(SchemaColumnType::DateTime, $def->columns[0]->type);
    }

    #[Test]
    public function timestampsAddsTwoNullableColumns(): void
    {
        $blueprint = new Blueprint('posts');
        $blueprint->timestamps();
        $def = $blueprint->toDefinition();

        self::assertCount(2, $def->columns);
        self::assertSame('created_at', $def->columns[0]->name);
        self::assertTrue($def->columns[0]->nullable);
        self::assertSame(SchemaDefaultExpression::CurrentTimestamp, $def->columns[0]->defaultExpression);
        self::assertSame('updated_at', $def->columns[1]->name);
        self::assertTrue($def->columns[1]->nullable);
    }

    #[Test]
    public function dateAddsDateColumn(): void
    {
        $blueprint = new Blueprint('events');
        $blueprint->date('event_date');
        $def = $blueprint->toDefinition();

        self::assertSame(SchemaColumnType::Date, $def->columns[0]->type);
    }

    #[Test]
    public function timeAddsTimeColumn(): void
    {
        $blueprint = new Blueprint('events');
        $blueprint->time('start_time');
        $def = $blueprint->toDefinition();

        self::assertSame(SchemaColumnType::Time, $def->columns[0]->type);
    }

    #[Test]
    public function floatAddsFloatColumn(): void
    {
        $blueprint = new Blueprint('measurements');
        $blueprint->float('temperature');
        $def = $blueprint->toDefinition();

        self::assertSame(SchemaColumnType::Float, $def->columns[0]->type);
    }

    #[Test]
    public function decimalAddsDecimalColumn(): void
    {
        $blueprint = new Blueprint('products');
        $blueprint->decimal('price', 10, 4);
        $def = $blueprint->toDefinition();

        $col = $def->columns[0];
        self::assertSame(SchemaColumnType::Decimal, $col->type);
        self::assertSame(10, $col->precision);
        self::assertSame(4, $col->scale);
    }

    #[Test]
    public function jsonAddsJsonColumn(): void
    {
        $blueprint = new Blueprint('configs');
        $blueprint->json('settings');
        $def = $blueprint->toDefinition();

        self::assertSame(SchemaColumnType::Json, $def->columns[0]->type);
    }

    #[Test]
    public function uuidAddsUuidColumn(): void
    {
        $blueprint = new Blueprint('tokens');
        $blueprint->uuid('token_id');
        $def = $blueprint->toDefinition();

        self::assertSame(SchemaColumnType::Uuid, $def->columns[0]->type);
    }

    #[Test]
    public function binaryAddsBinaryColumn(): void
    {
        $blueprint = new Blueprint('files');
        $blueprint->binary('data');
        $def = $blueprint->toDefinition();

        self::assertSame(SchemaColumnType::Binary, $def->columns[0]->type);
    }

    #[Test]
    public function enumAddsEnumColumn(): void
    {
        $blueprint = new Blueprint('orders');
        $blueprint->enum('status', ['pending', 'shipped', 'delivered']);
        $def = $blueprint->toDefinition();

        $col = $def->columns[0];
        self::assertSame(SchemaColumnType::Enum, $col->type);
        self::assertSame(['pending', 'shipped', 'delivered'], $col->enumValues);
    }

    #[Test]
    public function nullableModifierSetsNullable(): void
    {
        $blueprint = new Blueprint('users');
        $blueprint->string('bio')->nullable();
        $def = $blueprint->toDefinition();

        self::assertTrue($def->columns[0]->nullable);
    }

    #[Test]
    public function defaultModifierSetsDefaultValue(): void
    {
        $blueprint = new Blueprint('users');
        $blueprint->string('role')->default('user');
        $def = $blueprint->toDefinition();

        $col = $def->columns[0];
        self::assertTrue($col->hasDefault);
        self::assertSame('user', $col->default);
    }

    #[Test]
    public function uniqueModifierSetsUnique(): void
    {
        $blueprint = new Blueprint('users');
        $blueprint->string('email')->unique();
        $def = $blueprint->toDefinition();

        self::assertTrue($def->columns[0]->unique);
    }

    #[Test]
    public function indexAddsNonUniqueIndex(): void
    {
        $blueprint = new Blueprint('users');
        $blueprint->string('email');
        $blueprint->index('email');
        $def = $blueprint->toDefinition();

        self::assertCount(1, $def->indexes);
        self::assertFalse($def->indexes[0]->unique);
        self::assertSame(['email'], $def->indexes[0]->columns);
    }

    #[Test]
    public function uniqueIndexAddsUniqueIndex(): void
    {
        $blueprint = new Blueprint('users');
        $blueprint->string('email');
        $blueprint->unique(['email', 'tenant_id'], 'uq_custom');
        $def = $blueprint->toDefinition();

        self::assertCount(1, $def->indexes);
        self::assertTrue($def->indexes[0]->unique);
        self::assertSame('uq_custom', $def->indexes[0]->name);
        self::assertSame(['email', 'tenant_id'], $def->indexes[0]->columns);
    }

    #[Test]
    public function foreignIdAddsBigIntColumnAndConstraint(): void
    {
        $blueprint = new Blueprint('posts');
        $blueprint->id();
        $blueprint->foreignId('user_id')->references('id')->on('users');
        $def = $blueprint->toDefinition();

        // Column
        self::assertCount(2, $def->columns);
        $fkCol = $def->columns[1];
        self::assertSame('user_id', $fkCol->name);
        self::assertSame(SchemaColumnType::BigInt, $fkCol->type);
        self::assertTrue($fkCol->unsigned);

        // FK constraint
        self::assertCount(1, $def->foreignKeys);
        $fk = $def->foreignKeys[0];
        self::assertSame('fk_posts_user_id', $fk->name);
        self::assertSame(['user_id'], $fk->columns);
        self::assertSame('users', $fk->referencedTable);
        self::assertSame(['id'], $fk->referencedColumns);
    }

    #[Test]
    public function foreignIdSupportsOnDeleteCascade(): void
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
    public function foreignIdNullable(): void
    {
        $blueprint = new Blueprint('posts');
        $blueprint->foreignId('category_id')->nullable()->references('id')->on('categories');
        $def = $blueprint->toDefinition();

        self::assertTrue($def->columns[0]->nullable);
    }

    #[Test]
    public function multipleColumnsPreserveOrder(): void
    {
        $blueprint = new Blueprint('users');
        $blueprint->id();
        $blueprint->string('name');
        $blueprint->string('email')->unique();
        $blueprint->boolean('active')->default(true);
        $blueprint->timestamps();
        $def = $blueprint->toDefinition();

        self::assertCount(6, $def->columns);
        self::assertSame('id', $def->columns[0]->name);
        self::assertSame('name', $def->columns[1]->name);
        self::assertSame('email', $def->columns[2]->name);
        self::assertSame('active', $def->columns[3]->name);
        self::assertSame('created_at', $def->columns[4]->name);
        self::assertSame('updated_at', $def->columns[5]->name);
    }

    #[Test]
    public function toDefinitionIncludesTableName(): void
    {
        $blueprint = new Blueprint('my_table');
        $blueprint->id();
        $def = $blueprint->toDefinition();

        self::assertSame('my_table', $def->name);
    }

    #[Test]
    public function foreignMethodAddsDirectForeignKey(): void
    {
        $blueprint = new Blueprint('order_items');
        $blueprint->id();
        $blueprint->foreign(
            name: 'fk_order_items_order',
            columns: ['order_id'],
            referencedTable: 'orders',
            referencedColumns: ['id'],
            onDelete: SchemaReferentialAction::Cascade,
        );
        $def = $blueprint->toDefinition();

        self::assertCount(1, $def->foreignKeys);
        self::assertSame(SchemaReferentialAction::Cascade, $def->foreignKeys[0]->onDelete);
    }

    #[Test]
    public function defaultExpressionSetsRawSqlDefault(): void
    {
        $blueprint = new Blueprint('events');
        $blueprint->timestamp('created_at')
            ->defaultExpression(SchemaDefaultExpression::CurrentTimestamp);
        $def = $blueprint->toDefinition();

        self::assertSame(SchemaDefaultExpression::CurrentTimestamp, $def->columns[0]->defaultExpression);
    }
}
