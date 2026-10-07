<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Attribute;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Attribute\Column;
use Pulsar\Extension\Orm\Domain\ColumnType;

final class ColumnTest extends TestCase
{
    #[Test]
    public function defaultValues(): void
    {
        $col = new Column();

        self::assertNull($col->name);
        self::assertSame(ColumnType::String, $col->type);
        self::assertFalse($col->nullable);
        self::assertNull($col->length);
        self::assertNull($col->precision);
        self::assertNull($col->scale);
        self::assertTrue($col->insertable);
        self::assertTrue($col->updatable);
    }

    #[Test]
    public function customValues(): void
    {
        $col = new Column(
            name: 'price',
            type: ColumnType::Decimal,
            nullable: true,
            length: null,
            precision: 10,
            scale: 2,
            insertable: true,
            updatable: false,
        );

        self::assertSame('price', $col->name);
        self::assertSame(ColumnType::Decimal, $col->type);
        self::assertTrue($col->nullable);
        self::assertSame(10, $col->precision);
        self::assertSame(2, $col->scale);
        self::assertTrue($col->insertable);
        self::assertFalse($col->updatable);
    }
}
