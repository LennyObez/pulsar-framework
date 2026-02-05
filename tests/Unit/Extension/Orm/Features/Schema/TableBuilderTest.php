<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Orm\Features\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Domain\ColumnType;
use Pulsar\Extension\Orm\Features\Schema\TableBuilder;

#[CoversClass(TableBuilder::class)]
final class TableBuilderTest extends TestCase
{
    #[Test]
    public function idCreatesAutoIncrementBigIntPrimaryKey(): void
    {
        $builder = new TableBuilder('users');
        $col = $builder->id();

        self::assertCount(1, $builder->columns);
        self::assertSame('id', $col->name);
        self::assertSame(ColumnType::BigInt, $col->type);
        self::assertTrue($col->unsigned);
        self::assertTrue($col->autoIncrement);
        self::assertTrue($col->primaryKey);
        self::assertContains('id', $builder->primaryKeys);
    }

    #[Test]
    public function idWithCustomColumnName(): void
    {
        $builder = new TableBuilder('users');
        $col = $builder->id('user_id');

        self::assertSame('user_id', $col->name);
        self::assertContains('user_id', $builder->primaryKeys);
    }

    #[Test]
    public function uuidCreatesUuidPrimaryKey(): void
    {
        $builder = new TableBuilder('users');
        $col = $builder->uuid();

        self::assertSame(ColumnType::Uuid, $col->type);
        self::assertSame(36, $col->length);
        self::assertTrue($col->primaryKey);
        self::assertContains('id', $builder->primaryKeys);
    }

    #[Test]
    public function stringCreatesVarcharColumn(): void
    {
        $builder = new TableBuilder('users');
        $col = $builder->string('name');

        self::assertSame('name', $col->name);
        self::assertSame(ColumnType::String, $col->type);
        self::assertSame(255, $col->length);
    }

    #[Test]
    public function stringWithCustomLength(): void
    {
        $builder = new TableBuilder('users');
        $col = $builder->string('code', 10);

        self::assertSame(10, $col->length);
    }

    #[Test]
    public function textCreatesTextColumn(): void
    {
        $builder = new TableBuilder('posts');
        $col = $builder->text('body');

        self::assertSame(ColumnType::Text, $col->type);
    }

    #[Test]
    public function integerCreatesIntegerColumn(): void
    {
        $builder = new TableBuilder('users');
        $col = $builder->integer('age');

        self::assertSame(ColumnType::Integer, $col->type);
    }

    #[Test]
    public function bigIntegerCreatesBigIntColumn(): void
    {
        $builder = new TableBuilder('users');
        $col = $builder->bigInteger('views');

        self::assertSame(ColumnType::BigInt, $col->type);
    }

    #[Test]
    public function smallIntegerCreatesSmallIntColumn(): void
    {
        $builder = new TableBuilder('metrics');
        $col = $builder->smallInteger('score');

        self::assertSame(ColumnType::SmallInt, $col->type);
    }

    #[Test]
    public function floatCreatesFloatColumn(): void
    {
        $builder = new TableBuilder('products');
        $col = $builder->float('weight');

        self::assertSame(ColumnType::Float, $col->type);
    }

    #[Test]
    public function decimalCreatesDecimalWithPrecision(): void
    {
        $builder = new TableBuilder('products');
        $col = $builder->decimal('price', 10, 4);

        self::assertSame(ColumnType::Decimal, $col->type);
        self::assertSame(10, $col->precision);
        self::assertSame(4, $col->scale);
    }

    #[Test]
    public function decimalDefaultPrecision(): void
    {
        $builder = new TableBuilder('products');
        $col = $builder->decimal('price');

        self::assertSame(8, $col->precision);
        self::assertSame(2, $col->scale);
    }

    #[Test]
    public function booleanCreatesBooleanColumn(): void
    {
        $builder = new TableBuilder('users');
        $col = $builder->boolean('active');

        self::assertSame(ColumnType::Boolean, $col->type);
    }

    #[Test]
    public function dateTimeCreatesDateTimeColumn(): void
    {
        $builder = new TableBuilder('events');
        $col = $builder->dateTime('starts_at');

        self::assertSame(ColumnType::DateTime, $col->type);
    }

    #[Test]
    public function dateCreatesDateColumn(): void
    {
        $builder = new TableBuilder('events');
        $col = $builder->date('birthday');

        self::assertSame(ColumnType::Date, $col->type);
    }

    #[Test]
    public function timeCreatesTimeColumn(): void
    {
        $builder = new TableBuilder('schedule');
        $col = $builder->time('start_time');

        self::assertSame(ColumnType::Time, $col->type);
    }

    #[Test]
    public function jsonCreatesJsonColumn(): void
    {
        $builder = new TableBuilder('settings');
        $col = $builder->json('data');

        self::assertSame(ColumnType::Json, $col->type);
    }

    #[Test]
    public function binaryCreatesBinaryColumn(): void
    {
        $builder = new TableBuilder('files');
        $col = $builder->binary('content');

        self::assertSame(ColumnType::Binary, $col->type);
    }

    #[Test]
    public function enumCreatesEnumColumn(): void
    {
        $builder = new TableBuilder('users');
        $col = $builder->enum('status');

        self::assertSame(ColumnType::Enum, $col->type);
    }

    #[Test]
    public function timestampsAddsTwoNullableDateTimeColumns(): void
    {
        $builder = new TableBuilder('users');
        $builder->timestamps();

        self::assertCount(2, $builder->columns);
        self::assertSame('created_at', $builder->columns[0]->name);
        self::assertSame('updated_at', $builder->columns[1]->name);
        self::assertTrue($builder->columns[0]->nullable);
        self::assertTrue($builder->columns[1]->nullable);
        self::assertSame(ColumnType::DateTime, $builder->columns[0]->type);
        self::assertSame(ColumnType::DateTime, $builder->columns[1]->type);
    }

    #[Test]
    public function softDeletesAddsNullableDateTimeColumn(): void
    {
        $builder = new TableBuilder('users');
        $builder->softDeletes();

        self::assertCount(1, $builder->columns);
        self::assertSame('deleted_at', $builder->columns[0]->name);
        self::assertTrue($builder->columns[0]->nullable);
    }

    #[Test]
    public function softDeletesWithCustomColumnName(): void
    {
        $builder = new TableBuilder('users');
        $builder->softDeletes('removed_at');

        self::assertSame('removed_at', $builder->columns[0]->name);
    }

    #[Test]
    public function indexAddsIndexDefinition(): void
    {
        $builder = new TableBuilder('users');
        $builder->index(['email']);

        self::assertCount(1, $builder->indexes);
        self::assertSame('idx_users_email', $builder->indexes[0]->name);
        self::assertSame(['email'], $builder->indexes[0]->columns);
        self::assertFalse($builder->indexes[0]->unique);
    }

    #[Test]
    public function indexWithCustomName(): void
    {
        $builder = new TableBuilder('users');
        $builder->index(['first_name', 'last_name'], 'idx_full_name');

        self::assertSame('idx_full_name', $builder->indexes[0]->name);
        self::assertSame(['first_name', 'last_name'], $builder->indexes[0]->columns);
    }

    #[Test]
    public function uniqueAddsUniqueIndex(): void
    {
        $builder = new TableBuilder('users');
        $builder->unique(['email']);

        self::assertCount(1, $builder->indexes);
        self::assertSame('uniq_users_email', $builder->indexes[0]->name);
        self::assertTrue($builder->indexes[0]->unique);
    }

    #[Test]
    public function foreignAddsConstraint(): void
    {
        $builder = new TableBuilder('orders');
        $builder->foreign(['user_id'], 'users', ['id'], 'CASCADE', 'CASCADE');

        self::assertCount(1, $builder->foreignKeys);
        $fk = $builder->foreignKeys[0];
        self::assertSame('fk_orders_user_id', $fk->name);
        self::assertSame(['user_id'], $fk->columns);
        self::assertSame('users', $fk->referencedTable);
        self::assertSame(['id'], $fk->referencedColumns);
        self::assertSame('CASCADE', $fk->onDelete);
        self::assertSame('CASCADE', $fk->onUpdate);
    }

    #[Test]
    public function foreignDefaultsToRestrict(): void
    {
        $builder = new TableBuilder('orders');
        $builder->foreign(['user_id'], 'users', ['id']);

        $fk = $builder->foreignKeys[0];
        self::assertSame('RESTRICT', $fk->onDelete);
        self::assertSame('RESTRICT', $fk->onUpdate);
    }

    #[Test]
    public function dropColumnAddsToDropList(): void
    {
        $builder = new TableBuilder('users');
        $builder->dropColumn('legacy_field');

        self::assertContains('legacy_field', $builder->dropColumns);
    }

    #[Test]
    public function dropIndexAddsToDropList(): void
    {
        $builder = new TableBuilder('users');
        $builder->dropIndex('idx_users_email');

        self::assertContains('idx_users_email', $builder->dropIndexes);
    }

    #[Test]
    public function indexAndUniqueReturnFluentInterface(): void
    {
        $builder = new TableBuilder('users');

        self::assertSame($builder, $builder->index(['a']));
        self::assertSame($builder, $builder->unique(['b']));
        self::assertSame($builder, $builder->foreign(['c'], 'ref', ['d']));
        self::assertSame($builder, $builder->dropColumn('x'));
        self::assertSame($builder, $builder->dropIndex('y'));
    }
}
