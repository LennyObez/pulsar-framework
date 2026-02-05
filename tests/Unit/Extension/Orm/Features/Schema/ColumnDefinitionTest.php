<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Orm\Features\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Domain\ColumnType;
use Pulsar\Extension\Orm\Features\Schema\ColumnDefinition;

#[CoversClass(ColumnDefinition::class)]
final class ColumnDefinitionTest extends TestCase
{
    #[Test]
    public function constructorSetsNameAndType(): void
    {
        $col = new ColumnDefinition('email', ColumnType::String);

        self::assertSame('email', $col->name);
        self::assertSame(ColumnType::String, $col->type);
    }

    #[Test]
    public function defaultsAreCorrect(): void
    {
        $col = new ColumnDefinition('test', ColumnType::Integer);

        self::assertFalse($col->nullable);
        self::assertNull($col->default);
        self::assertFalse($col->hasDefault);
        self::assertFalse($col->unsigned);
        self::assertFalse($col->autoIncrement);
        self::assertFalse($col->primaryKey);
        self::assertFalse($col->unique);
        self::assertNull($col->length);
        self::assertNull($col->precision);
        self::assertNull($col->scale);
        self::assertNull($col->after);
    }

    #[Test]
    public function nullableSetsFlag(): void
    {
        $col = new ColumnDefinition('email', ColumnType::String);
        $result = $col->nullable();

        self::assertTrue($col->nullable);
        self::assertSame($col, $result);
    }

    #[Test]
    public function nullableCanBeSetToFalse(): void
    {
        $col = new ColumnDefinition('email', ColumnType::String);
        $col->nullable(true);
        $col->nullable(false);

        self::assertFalse($col->nullable);
    }

    #[Test]
    public function defaultSetsValueAndFlag(): void
    {
        $col = new ColumnDefinition('status', ColumnType::String);
        $result = $col->default('active');

        self::assertSame('active', $col->default);
        self::assertTrue($col->hasDefault);
        self::assertSame($col, $result);
    }

    #[Test]
    public function defaultCanBeNull(): void
    {
        $col = new ColumnDefinition('deleted_at', ColumnType::DateTime);
        $col->default(null);

        self::assertNull($col->default);
        self::assertTrue($col->hasDefault);
    }

    #[Test]
    public function defaultCanBeZero(): void
    {
        $col = new ColumnDefinition('counter', ColumnType::Integer);
        $col->default(0);

        self::assertSame(0, $col->default);
        self::assertTrue($col->hasDefault);
    }

    #[Test]
    public function defaultCanBeFalse(): void
    {
        $col = new ColumnDefinition('active', ColumnType::Boolean);
        $col->default(false);

        self::assertFalse($col->default);
        self::assertTrue($col->hasDefault);
    }

    #[Test]
    public function unsignedSetsFlag(): void
    {
        $col = new ColumnDefinition('age', ColumnType::Integer);
        $result = $col->unsigned();

        self::assertTrue($col->unsigned);
        self::assertSame($col, $result);
    }

    #[Test]
    public function unsignedCanBeToggled(): void
    {
        $col = new ColumnDefinition('age', ColumnType::Integer);
        $col->unsigned(true);
        $col->unsigned(false);

        self::assertFalse($col->unsigned);
    }

    #[Test]
    public function autoIncrementSetsFlag(): void
    {
        $col = new ColumnDefinition('id', ColumnType::BigInt);
        $result = $col->autoIncrement();

        self::assertTrue($col->autoIncrement);
        self::assertSame($col, $result);
    }

    #[Test]
    public function primarySetsFlag(): void
    {
        $col = new ColumnDefinition('id', ColumnType::BigInt);
        $result = $col->primary();

        self::assertTrue($col->primaryKey);
        self::assertSame($col, $result);
    }

    #[Test]
    public function uniqueSetsFlag(): void
    {
        $col = new ColumnDefinition('email', ColumnType::String);
        $result = $col->unique();

        self::assertTrue($col->unique);
        self::assertSame($col, $result);
    }

    #[Test]
    public function lengthSetsValue(): void
    {
        $col = new ColumnDefinition('code', ColumnType::String);
        $result = $col->length(50);

        self::assertSame(50, $col->length);
        self::assertSame($col, $result);
    }

    #[Test]
    public function precisionSetsBothPrecisionAndScale(): void
    {
        $col = new ColumnDefinition('price', ColumnType::Decimal);
        $result = $col->precision(10, 4);

        self::assertSame(10, $col->precision);
        self::assertSame(4, $col->scale);
        self::assertSame($col, $result);
    }

    #[Test]
    public function precisionDefaultsScaleToZero(): void
    {
        $col = new ColumnDefinition('amount', ColumnType::Decimal);
        $col->precision(8);

        self::assertSame(8, $col->precision);
        self::assertSame(0, $col->scale);
    }

    #[Test]
    public function afterSetsValue(): void
    {
        $col = new ColumnDefinition('middle_name', ColumnType::String);
        $result = $col->after('first_name');

        self::assertSame('first_name', $col->after);
        self::assertSame($col, $result);
    }

    #[Test]
    public function fluentChainingWorksCorrectly(): void
    {
        $col = new ColumnDefinition('price', ColumnType::Decimal);
        $col->precision(10, 2)
            ->unsigned()
            ->nullable()
            ->default(0.00);

        self::assertSame(10, $col->precision);
        self::assertSame(2, $col->scale);
        self::assertTrue($col->unsigned);
        self::assertTrue($col->nullable);
        self::assertSame(0.00, $col->default);
        self::assertTrue($col->hasDefault);
    }
}
