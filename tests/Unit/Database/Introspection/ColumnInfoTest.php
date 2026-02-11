<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Introspection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Introspection\ColumnInfo;

#[CoversClass(ColumnInfo::class)]
final class ColumnInfoTest extends TestCase
{
    #[Test]
    public function constructWithAllProperties(): void
    {
        $info = new ColumnInfo(
            name: 'id',
            type: 'integer',
            nullable: false,
            isPrimaryKey: true,
            default: null,
        );

        self::assertSame('id', $info->name);
        self::assertSame('integer', $info->type);
        self::assertFalse($info->nullable);
        self::assertTrue($info->isPrimaryKey);
        self::assertNull($info->default);
    }

    #[Test]
    public function constructWithDefault(): void
    {
        $info = new ColumnInfo(
            name: 'status',
            type: 'varchar(50)',
            nullable: true,
            isPrimaryKey: false,
            default: 'active',
        );

        self::assertSame('status', $info->name);
        self::assertTrue($info->nullable);
        self::assertFalse($info->isPrimaryKey);
        self::assertSame('active', $info->default);
    }
}
