<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Internal\Adapter;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Internal\Adapter\SqlIdentifierValidator;

use function str_repeat;

#[CoversClass(SqlIdentifierValidator::class)]
final class SqlIdentifierValidatorTest extends TestCase
{
    use SqlIdentifierValidator {
        quoteIdentifier as public;
    }

    #[Test]
    #[DataProvider('validIdentifierProvider')]
    public function validIdentifierReturnsBacktickQuoted(string $identifier, string $expected): void
    {
        self::assertSame($expected, $this->quoteIdentifier($identifier));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function validIdentifierProvider(): iterable
    {
        yield 'simple' => ['users', '`users`'];
        yield 'underscore in middle' => ['user_roles', '`user_roles`'];
        yield 'underscore prefix' => ['_internal', '`_internal`'];
        yield 'mixed case' => ['UserRoles', '`UserRoles`'];
        yield 'with numbers' => ['table_123', '`table_123`'];
        yield 'single char' => ['a', '`a`'];
    }

    #[Test]
    public function maxLengthIdentifierIsValid(): void
    {
        $identifier = 'a' . str_repeat('b', 127); // 128 chars

        self::assertSame("`$identifier`", $this->quoteIdentifier($identifier));
    }

    #[Test]
    #[DataProvider('invalidIdentifierProvider')]
    public function invalidIdentifierThrows(string $identifier): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Invalid SQL identifier/');

        $this->quoteIdentifier($identifier);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidIdentifierProvider(): iterable
    {
        yield 'empty string' => [''];
        yield 'starts with digit' => ['1table'];
        yield 'contains space' => ['table name'];
        yield 'contains dash' => ['table-name'];
        yield 'contains dot' => ['schema.table'];
        yield 'contains semicolon' => ['table;DROP'];
        yield 'sql injection attempt' => ['users; DROP TABLE'];
        yield 'contains backtick' => ['table`name'];
        yield 'backtick injection' => ['users`; --'];
        yield 'too long' => ['a' . str_repeat('b', 128)]; // 129 chars
    }
}
