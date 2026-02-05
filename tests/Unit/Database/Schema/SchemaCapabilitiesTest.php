<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Driver;
use Pulsar\Database\Schema\SchemaCapabilities;

#[CoversClass(SchemaCapabilities::class)]
final class SchemaCapabilitiesTest extends TestCase
{
    #[Test]
    public function sqliteCapabilities(): void
    {
        $cap = new SchemaCapabilities(Driver::SQLite);

        self::assertFalse($cap->supportsDropColumn()); // no connection, defaults false
        self::assertFalse($cap->supportsAlterColumnType());
        self::assertTrue($cap->supportsForeignKeySyntax());
        self::assertFalse($cap->foreignKeysEnforcedByDefault());
        self::assertFalse($cap->supportsTransactionalDdl());
        self::assertFalse($cap->supportsNativeEnum());
        self::assertFalse($cap->supportsAddForeignKey());
        self::assertFalse($cap->supportsDropForeignKey());
        self::assertFalse($cap->supportsUnsigned());
    }

    #[Test]
    public function pgsqlCapabilities(): void
    {
        $cap = new SchemaCapabilities(Driver::PostgreSQL);

        self::assertTrue($cap->supportsDropColumn());
        self::assertTrue($cap->supportsAlterColumnType());
        self::assertTrue($cap->supportsForeignKeySyntax());
        self::assertTrue($cap->foreignKeysEnforcedByDefault());
        self::assertTrue($cap->supportsTransactionalDdl());
        self::assertFalse($cap->supportsNativeEnum());
        self::assertTrue($cap->supportsAddForeignKey());
        self::assertTrue($cap->supportsDropForeignKey());
        self::assertFalse($cap->supportsUnsigned());
    }

    #[Test]
    public function mysqlCapabilities(): void
    {
        $cap = new SchemaCapabilities(Driver::MySQL);

        self::assertTrue($cap->supportsDropColumn());
        self::assertTrue($cap->supportsAlterColumnType());
        self::assertTrue($cap->supportsForeignKeySyntax());
        self::assertTrue($cap->foreignKeysEnforcedByDefault());
        self::assertFalse($cap->supportsTransactionalDdl());
        self::assertTrue($cap->supportsNativeEnum());
        self::assertTrue($cap->supportsAddForeignKey());
        self::assertTrue($cap->supportsDropForeignKey());
        self::assertTrue($cap->supportsUnsigned());
    }

    #[Test]
    public function toArrayReturnsAllKeys(): void
    {
        $cap = new SchemaCapabilities(Driver::SQLite);
        $array = $cap->toArray();

        self::assertArrayHasKey('supportsDropColumn', $array);
        self::assertArrayHasKey('supportsAlterColumnType', $array);
        self::assertArrayHasKey('supportsForeignKeys', $array);
        self::assertArrayHasKey('supportsTransactionalDdl', $array);
        self::assertArrayHasKey('supportsNativeEnum', $array);
        self::assertArrayHasKey('supportsAddForeignKey', $array);
        self::assertArrayHasKey('supportsDropForeignKey', $array);
        self::assertArrayHasKey('supportsUnsigned', $array);
    }
}
