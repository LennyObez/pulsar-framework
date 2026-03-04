<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Schema\SchemaColumn;
use Pulsar\Database\Schema\SchemaColumnType;
use Pulsar\Database\Schema\SchemaDefaultExpression;
use ReflectionClass;

#[CoversClass(SchemaColumn::class)]
final class SchemaColumnTest extends TestCase
{
    #[Test]
    public function constructsWithMinimalArguments(): void
    {
        $column = new SchemaColumn(name: 'id', type: SchemaColumnType::Integer);

        self::assertSame('id', $column->name);
        self::assertSame(SchemaColumnType::Integer, $column->type);
        self::assertFalse($column->nullable);
        self::assertFalse($column->primaryKey);
        self::assertFalse($column->autoIncrement);
        self::assertFalse($column->unsigned);
        self::assertFalse($column->unique);
        self::assertNull($column->default);
        self::assertFalse($column->hasDefault);
        self::assertNull($column->defaultExpression);
        self::assertNull($column->length);
        self::assertNull($column->precision);
        self::assertNull($column->scale);
        self::assertSame([], $column->enumValues);
    }

    #[Test]
    public function constructsWithAllArguments(): void
    {
        $column = new SchemaColumn(
            name: 'price',
            type: SchemaColumnType::Decimal,
            nullable: true,
            primaryKey: false,
            autoIncrement: false,
            unsigned: true,
            unique: false,
            default: '0.00',
            hasDefault: true,
            defaultExpression: null,
            length: null,
            precision: 10,
            scale: 2,
            enumValues: [],
        );

        self::assertSame('price', $column->name);
        self::assertSame(SchemaColumnType::Decimal, $column->type);
        self::assertTrue($column->nullable);
        self::assertTrue($column->unsigned);
        self::assertSame('0.00', $column->default);
        self::assertTrue($column->hasDefault);
        self::assertSame(10, $column->precision);
        self::assertSame(2, $column->scale);
    }

    #[Test]
    public function constructsPrimaryKeyAutoIncrement(): void
    {
        $column = new SchemaColumn(
            name: 'id',
            type: SchemaColumnType::BigInt,
            primaryKey: true,
            autoIncrement: true,
            unsigned: true,
        );

        self::assertTrue($column->primaryKey);
        self::assertTrue($column->autoIncrement);
        self::assertTrue($column->unsigned);
    }

    #[Test]
    public function constructsWithDefaultExpression(): void
    {
        $column = new SchemaColumn(
            name: 'created_at',
            type: SchemaColumnType::DateTime,
            hasDefault: true,
            defaultExpression: SchemaDefaultExpression::CurrentTimestamp,
        );

        self::assertSame(SchemaDefaultExpression::CurrentTimestamp, $column->defaultExpression);
        self::assertTrue($column->hasDefault);
    }

    #[Test]
    public function constructsWithEnumValues(): void
    {
        $column = new SchemaColumn(
            name: 'status',
            type: SchemaColumnType::Enum,
            enumValues: ['active', 'inactive', 'pending'],
        );

        self::assertSame(SchemaColumnType::Enum, $column->type);
        self::assertSame(['active', 'inactive', 'pending'], $column->enumValues);
    }

    #[Test]
    public function constructsWithStringLength(): void
    {
        $column = new SchemaColumn(
            name: 'email',
            type: SchemaColumnType::String,
            unique: true,
            length: 255,
        );

        self::assertSame(255, $column->length);
        self::assertTrue($column->unique);
    }

    #[Test]
    public function constructsNullableWithNullDefault(): void
    {
        $column = new SchemaColumn(
            name: 'deleted_at',
            type: SchemaColumnType::DateTime,
            nullable: true,
            default: null,
            hasDefault: true,
        );

        self::assertTrue($column->nullable);
        self::assertNull($column->default);
        self::assertTrue($column->hasDefault);
    }

    #[Test]
    #[DataProvider('scalarDefaultsProvider')]
    public function constructsWithVariousScalarDefaults(int|float|string|bool $default): void
    {
        $column = new SchemaColumn(
            name: 'test_col',
            type: SchemaColumnType::String,
            default: $default,
            hasDefault: true,
        );

        self::assertSame($default, $column->default);
    }

    /**
     * @return iterable<string, array{int|float|string|bool}>
     */
    public static function scalarDefaultsProvider(): iterable
    {
        yield 'int 0' => [0];
        yield 'int 42' => [42];
        yield 'float 3.14' => [3.14];
        yield 'string empty' => [''];
        yield 'string value' => ['default_val'];
        yield 'bool true' => [true];
        yield 'bool false' => [false];
    }

    #[Test]
    public function isReadonly(): void
    {
        $column = new SchemaColumn(name: 'test', type: SchemaColumnType::Text);

        $reflection = new ReflectionClass($column);
        self::assertTrue($reflection->isReadOnly());
    }
}
