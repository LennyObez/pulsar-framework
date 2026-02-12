<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Features\Schema;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Domain\ColumnType;
use Pulsar\Extension\Orm\Features\Schema\TableBuilder;

final class TableBuilderTest extends TestCase
{
    #[Test]
    public function idCreatesAutoIncrementPrimaryKey(): void
    {
        $builder = new TableBuilder('users');
        $col = $builder->id();

        self::assertSame('id', $col->name);
        self::assertSame(ColumnType::BigInt, $col->type);
        self::assertTrue($col->unsigned);
        self::assertTrue($col->autoIncrement);
        self::assertTrue($col->primaryKey);
        self::assertSame(['id'], $builder->primaryKeys);
    }

    #[Test]
    public function uuidCreatesPrimaryKeyWithLength(): void
    {
        $builder = new TableBuilder('orders');
        $col = $builder->uuid('order_id');

        self::assertSame('order_id', $col->name);
        self::assertSame(ColumnType::Uuid, $col->type);
        self::assertSame(36, $col->length);
        self::assertTrue($col->primaryKey);
    }

    #[Test]
    public function stringColumnWithDefaultLength(): void
    {
        $builder = new TableBuilder('users');
        $col = $builder->string('name');

        self::assertSame(ColumnType::String, $col->type);
        self::assertSame(255, $col->length);
    }

    #[Test]
    public function stringColumnWithCustomLength(): void
    {
        $builder = new TableBuilder('users');
        $col = $builder->string('code', 10);

        self::assertSame(10, $col->length);
    }

    #[Test]
    public function allColumnTypes(): void
    {
        $builder = new TableBuilder('test');

        self::assertSame(ColumnType::Text, $builder->text('body')->type);
        self::assertSame(ColumnType::Integer, $builder->integer('count')->type);
        self::assertSame(ColumnType::BigInt, $builder->bigInteger('big')->type);
        self::assertSame(ColumnType::SmallInt, $builder->smallInteger('small')->type);
        self::assertSame(ColumnType::Float, $builder->float('ratio')->type);
        self::assertSame(ColumnType::Boolean, $builder->boolean('active')->type);
        self::assertSame(ColumnType::DateTime, $builder->dateTime('when')->type);
        self::assertSame(ColumnType::Date, $builder->date('day')->type);
        self::assertSame(ColumnType::Time, $builder->time('clock')->type);
        self::assertSame(ColumnType::Json, $builder->json('data')->type);
        self::assertSame(ColumnType::Binary, $builder->binary('blob')->type);
        self::assertSame(ColumnType::Enum, $builder->enum('status')->type);
    }

    #[Test]
    public function decimalColumnWithPrecisionAndScale(): void
    {
        $builder = new TableBuilder('products');
        $col = $builder->decimal('price', 10, 4);

        self::assertSame(ColumnType::Decimal, $col->type);
        self::assertSame(10, $col->precision);
        self::assertSame(4, $col->scale);
    }

    #[Test]
    public function timestampsAddsCreatedAndUpdated(): void
    {
        $builder = new TableBuilder('posts');
        $builder->timestamps();

        self::assertCount(2, $builder->columns);
        self::assertSame('created_at', $builder->columns[0]->name);
        self::assertSame('updated_at', $builder->columns[1]->name);
        self::assertTrue($builder->columns[0]->nullable);
        self::assertTrue($builder->columns[1]->nullable);
    }

    #[Test]
    public function softDeletesAddsNullableDateTimeColumn(): void
    {
        $builder = new TableBuilder('posts');
        $builder->softDeletes();

        self::assertCount(1, $builder->columns);
        self::assertSame('deleted_at', $builder->columns[0]->name);
        self::assertSame(ColumnType::DateTime, $builder->columns[0]->type);
        self::assertTrue($builder->columns[0]->nullable);
    }

    #[Test]
    public function indexAddsIndexDefinition(): void
    {
        $builder = new TableBuilder('users');
        $builder->index(['email']);

        self::assertCount(1, $builder->indexes);
        self::assertSame(['email'], $builder->indexes[0]->columns);
        self::assertFalse($builder->indexes[0]->unique);
        self::assertSame('idx_users_email', $builder->indexes[0]->name);
    }

    #[Test]
    public function uniqueAddsUniqueIndex(): void
    {
        $builder = new TableBuilder('users');
        $builder->unique(['email'], 'custom_name');

        self::assertCount(1, $builder->indexes);
        self::assertTrue($builder->indexes[0]->unique);
        self::assertSame('custom_name', $builder->indexes[0]->name);
    }

    #[Test]
    public function foreignAddsForeignKey(): void
    {
        $builder = new TableBuilder('orders');
        $builder->foreign(['user_id'], 'users', ['id'], 'CASCADE');

        self::assertCount(1, $builder->foreignKeys);
        self::assertSame('fk_orders_user_id', $builder->foreignKeys[0]->name);
        self::assertSame('CASCADE', $builder->foreignKeys[0]->onDelete);
    }

    #[Test]
    public function dropColumnAndDropIndex(): void
    {
        $builder = new TableBuilder('users');
        $builder->dropColumn('legacy_field');
        $builder->dropIndex('idx_old');

        self::assertSame(['legacy_field'], $builder->dropColumns);
        self::assertSame(['idx_old'], $builder->dropIndexes);
    }
}
