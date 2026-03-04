<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\SqlIdentifier;

final class SqlIdentifierTest extends TestCase
{
    #[Test]
    #[DataProvider('validIdentifiers')]
    public function validate_accepts_valid_identifiers(string $identifier): void
    {
        self::assertSame($identifier, SqlIdentifier::validate($identifier));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validIdentifiers(): iterable
    {
        yield 'simple lowercase' => ['users'];
        yield 'simple uppercase' => ['USERS'];
        yield 'mixed case' => ['UserId'];
        yield 'with underscore' => ['user_id'];
        yield 'leading underscore' => ['_private'];
        yield 'single char' => ['a'];
        yield 'single underscore' => ['_'];
        yield 'numbers after first char' => ['col123'];
        yield 'max length 128 chars' => [str_repeat('a', 128)];
    }

    #[Test]
    #[DataProvider('invalidIdentifiers')]
    public function validate_rejects_invalid_identifiers(string $identifier): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid SQL identifier');

        SqlIdentifier::validate($identifier);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidIdentifiers(): iterable
    {
        yield 'empty string' => [''];
        yield 'starts with number' => ['1column'];
        yield 'contains space' => ['user name'];
        yield 'contains dash' => ['user-name'];
        yield 'contains dot' => ['schema.table'];
        yield 'contains backtick' => ['`users`'];
        yield 'contains semicolon' => ['users;'];
        yield 'contains single quote' => ["user's"];
        yield 'contains double quote' => ['user"s'];
        yield 'SQL injection attempt' => ['users; DROP TABLE users'];
        yield 'too long (129 chars)' => [str_repeat('a', 129)];
    }

    #[Test]
    public function quote_wraps_valid_identifier_in_backticks(): void
    {
        self::assertSame('`users`', SqlIdentifier::quote('users'));
        self::assertSame('`user_id`', SqlIdentifier::quote('user_id'));
    }

    #[Test]
    public function quote_rejects_invalid_identifier(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SqlIdentifier::quote('invalid;name');
    }
}
