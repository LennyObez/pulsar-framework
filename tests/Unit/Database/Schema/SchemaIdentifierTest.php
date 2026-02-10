<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Schema\SchemaException;
use Pulsar\Database\Schema\SchemaIdentifier;

#[CoversClass(SchemaIdentifier::class)]
final class SchemaIdentifierTest extends TestCase
{
    #[Test]
    public function validTableNamePasses(): void
    {
        SchemaIdentifier::validateTable('users');
        self::assertTrue(true, 'users accepted');
        SchemaIdentifier::validateTable('_internal');
        self::assertTrue(true, '_internal accepted');
        SchemaIdentifier::validateTable('my_table_123');
        self::assertTrue(true, 'my_table_123 accepted');
    }

    #[Test]
    public function validColumnNamePasses(): void
    {
        SchemaIdentifier::validateColumn('email');
        self::assertTrue(true, 'email accepted');
        SchemaIdentifier::validateColumn('first_name');
        self::assertTrue(true, 'first_name accepted');
    }

    #[Test]
    public function emptyStringThrows(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('must not be empty');
        SchemaIdentifier::validateTable('');
    }

    #[Test]
    public function startsWithNumberThrows(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('must start with a letter or underscore');
        SchemaIdentifier::validateTable('1table');
    }

    #[Test]
    public function specialCharsThrow(): void
    {
        $this->expectException(SchemaException::class);
        SchemaIdentifier::validateTable('my-table');
    }

    #[Test]
    public function dotNotAllowed(): void
    {
        $this->expectException(SchemaException::class);
        SchemaIdentifier::validateTable('schema.table');
    }

    #[Test]
    public function tooLongThrows(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('must not exceed 64 characters');
        SchemaIdentifier::validateTable(str_repeat('a', 65));
    }

    #[Test]
    #[DataProvider('reservedWordsProvider')]
    public function reservedWordThrows(string $word): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('reserved SQL word');
        SchemaIdentifier::validateTable($word);
    }

    /** @return iterable<string, array{string}> */
    public static function reservedWordsProvider(): iterable
    {
        yield 'select' => ['select'];
        yield 'SELECT (uppercase)' => ['SELECT'];
        yield 'table' => ['table'];
        yield 'from' => ['from'];
        yield 'where' => ['where'];
        yield 'order' => ['order'];
    }

    #[Test]
    public function validateIndexWorks(): void
    {
        SchemaIdentifier::validateIndex('idx_users_email');
        self::assertTrue(true, 'idx_users_email accepted');
    }

    #[Test]
    public function validateForeignKeyWorks(): void
    {
        SchemaIdentifier::validateForeignKey('fk_orders_user_id');
        self::assertTrue(true, 'fk_orders_user_id accepted');
    }
}
