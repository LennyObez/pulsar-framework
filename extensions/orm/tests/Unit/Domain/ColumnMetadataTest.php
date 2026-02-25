<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Domain\ColumnMetadata;
use Pulsar\Extension\Orm\Domain\ColumnType;

final class ColumnMetadataTest extends TestCase
{
    #[Test]
    public function constructionWithDefaults(): void
    {
        $col = new ColumnMetadata(
            propertyName: 'email',
            columnName: 'email',
            type: ColumnType::String,
        );

        self::assertSame('email', $col->propertyName);
        self::assertSame('email', $col->columnName);
        self::assertSame(ColumnType::String, $col->type);
        self::assertFalse($col->nullable);
        self::assertFalse($col->isPrimaryKey);
        self::assertFalse($col->autoIncrement);
        self::assertFalse($col->isVersion);
        self::assertFalse($col->encrypted);
        self::assertNull($col->blindIndexColumn);
        self::assertNull($col->blindIndexHashLength);
        self::assertTrue($col->insertable);
        self::assertTrue($col->updatable);
        self::assertNull($col->casterClass);
    }

    #[Test]
    public function constructionWithAllValues(): void
    {
        $col = new ColumnMetadata(
            propertyName: 'id',
            columnName: 'user_id',
            type: ColumnType::BigInt,
            nullable: false,
            isPrimaryKey: true,
            autoIncrement: true,
            isVersion: false,
            encrypted: false,
            blindIndexColumn: null,
            blindIndexHashLength: null,
            insertable: false,
            updatable: false,
            casterClass: 'App\\Caster',
        );

        self::assertSame('id', $col->propertyName);
        self::assertSame('user_id', $col->columnName);
        self::assertSame(ColumnType::BigInt, $col->type);
        self::assertTrue($col->isPrimaryKey);
        self::assertTrue($col->autoIncrement);
        self::assertFalse($col->insertable);
        self::assertFalse($col->updatable);
        self::assertSame('App\\Caster', $col->casterClass);
    }

    #[Test]
    public function encryptedColumnWithBlindIndex(): void
    {
        $col = new ColumnMetadata(
            propertyName: 'ssn',
            columnName: 'ssn_enc',
            type: ColumnType::Binary,
            encrypted: true,
            blindIndexColumn: 'ssn_idx',
            blindIndexHashLength: 16,
        );

        self::assertTrue($col->encrypted);
        self::assertSame('ssn_idx', $col->blindIndexColumn);
        self::assertSame(16, $col->blindIndexHashLength);
    }
}
