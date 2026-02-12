<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Features\Schema;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Domain\ColumnType;
use Pulsar\Extension\Orm\Features\Schema\ColumnDefinition;

final class ColumnDefinitionTest extends TestCase
{
    #[Test]
    public function constructionWithDefaults(): void
    {
        $col = new ColumnDefinition('email', ColumnType::String);

        self::assertSame('email', $col->name);
        self::assertSame(ColumnType::String, $col->type);
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
    public function fluentNullable(): void
    {
        $col = new ColumnDefinition('name', ColumnType::String);
        $result = $col->nullable();

        self::assertSame($col, $result);
        self::assertTrue($col->nullable);
    }

    #[Test]
    public function fluentDefault(): void
    {
        $col = new ColumnDefinition('status', ColumnType::String);
        $col->default('active');

        self::assertTrue($col->hasDefault);
        self::assertSame('active', $col->default);
    }

    #[Test]
    public function fluentUnsigned(): void
    {
        $col = new ColumnDefinition('age', ColumnType::Integer);
        $col->unsigned();

        self::assertTrue($col->unsigned);
    }

    #[Test]
    public function fluentAutoIncrement(): void
    {
        $col = new ColumnDefinition('id', ColumnType::BigInt);
        $col->autoIncrement();

        self::assertTrue($col->autoIncrement);
    }

    #[Test]
    public function fluentPrimary(): void
    {
        $col = new ColumnDefinition('id', ColumnType::BigInt);
        $col->primary();

        self::assertTrue($col->primaryKey);
    }

    #[Test]
    public function fluentUnique(): void
    {
        $col = new ColumnDefinition('email', ColumnType::String);
        $col->unique();

        self::assertTrue($col->unique);
    }

    #[Test]
    public function fluentLength(): void
    {
        $col = new ColumnDefinition('name', ColumnType::String);
        $col->length(100);

        self::assertSame(100, $col->length);
    }

    #[Test]
    public function fluentPrecision(): void
    {
        $col = new ColumnDefinition('amount', ColumnType::Decimal);
        $col->precision(10, 4);

        self::assertSame(10, $col->precision);
        self::assertSame(4, $col->scale);
    }

    #[Test]
    public function fluentAfter(): void
    {
        $col = new ColumnDefinition('middle_name', ColumnType::String);
        $col->after('first_name');

        self::assertSame('first_name', $col->after);
    }

    #[Test]
    public function chainingMultipleMethods(): void
    {
        $col = new ColumnDefinition('price', ColumnType::Decimal);
        $col->precision(8, 2)->nullable()->default(0.0)->unsigned();

        self::assertTrue($col->nullable);
        self::assertTrue($col->hasDefault);
        self::assertSame(0.0, $col->default);
        self::assertTrue($col->unsigned);
        self::assertSame(8, $col->precision);
        self::assertSame(2, $col->scale);
    }
}
