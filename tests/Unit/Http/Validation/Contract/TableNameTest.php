<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Contract;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Contract\InvalidIdentifierException;
use Pulsar\Http\Validation\Contract\TableName;

#[CoversClass(TableName::class)]
#[CoversClass(InvalidIdentifierException::class)]
final class TableNameTest extends TestCase
{
    #[Test]
    public function validTableName(): void
    {
        $name = new TableName('users');

        self::assertSame('users', $name->value);
    }

    #[Test]
    public function validTableNameWithUnderscore(): void
    {
        $name = new TableName('user_profiles');

        self::assertSame('user_profiles', $name->value);
    }

    #[Test]
    public function validTableNameStartingWithUnderscore(): void
    {
        $name = new TableName('_migrations');

        self::assertSame('_migrations', $name->value);
    }

    #[Test]
    public function validTableNameWithNumbers(): void
    {
        $name = new TableName('table2');

        self::assertSame('table2', $name->value);
    }

    #[Test]
    #[DataProvider('invalidIdentifierProvider')]
    public function rejectsInvalidIdentifier(string $invalid): void
    {
        $this->expectException(InvalidIdentifierException::class);

        new TableName($invalid);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidIdentifierProvider(): iterable
    {
        yield 'empty string' => [''];
        yield 'contains spaces' => ['user profiles'];
        yield 'contains dash' => ['user-profiles'];
        yield 'starts with number' => ['2table'];
        yield 'contains dot' => ['schema.table'];
        yield 'contains semicolon' => ['users;'];
        yield 'SQL injection attempt' => ["users'; DROP TABLE users;--"];
    }

    #[Test]
    #[DataProvider('sqlKeywordProvider')]
    public function rejectsSqlKeywords(string $keyword): void
    {
        $this->expectException(InvalidIdentifierException::class);

        new TableName($keyword);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function sqlKeywordProvider(): iterable
    {
        yield 'SELECT' => ['SELECT'];
        yield 'select lowercase' => ['select'];
        yield 'DROP' => ['DROP'];
        yield 'DELETE' => ['DELETE'];
        yield 'INSERT' => ['INSERT'];
        yield 'UPDATE' => ['UPDATE'];
        yield 'TRUNCATE' => ['TRUNCATE'];
    }

    #[Test]
    public function exceptionMessageContainsValue(): void
    {
        $this->expectException(InvalidIdentifierException::class);
        $this->expectExceptionMessageIsOrContains('Invalid table name: "DROP"');

        new TableName('DROP');
    }
}
