<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Driver;
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
        yield 'at the portable limit of 63' => [str_repeat('a', 63)];
    }

    #[Test]
    #[DataProvider('invalidIdentifiers')]
    public function validate_rejects_invalid_identifiers(string $identifier): void
    {
        $this->expectException(InvalidArgumentException::class);

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
    }

    /**
     * The bound is the strictest engine's, not the most generous.
     *
     * MySQL accepts 64 characters and PostgreSQL 63. Admitting 64 would produce a schema
     * that migrates on one supported engine and fails on another — a failure discovered
     * in production on whichever engine was not used in development.
     */
    #[Test]
    public function validate_rejects_a_name_longer_than_the_strictest_engine_accepts(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('portable limit');

        SqlIdentifier::validate(str_repeat('a', 64));
    }

    /**
     * Backticks are not an alternative spelling in PostgreSQL — they are a syntax error.
     */
    #[Test]
    #[DataProvider('quotingPerEngine')]
    public function quote_delimits_the_way_the_engine_expects(Driver $driver, string $expected): void
    {
        self::assertSame($expected, SqlIdentifier::quote('users', $driver));
    }

    /**
     * @return iterable<string, array{Driver, string}>
     */
    public static function quotingPerEngine(): iterable
    {
        yield 'MySQL uses backticks' => [Driver::MySQL, '`users`'];
        yield 'PostgreSQL uses the standard double quote' => [Driver::PostgreSQL, '"users"'];
        yield 'SQLite uses the standard double quote' => [Driver::SQLite, '"users"'];
    }

    #[Test]
    #[DataProvider('quotingPerEngine')]
    public function delimiter_matches_what_quote_produces(Driver $driver, string $expected): void
    {
        $delimiter = SqlIdentifier::delimiter($driver);

        self::assertSame($expected, $delimiter . 'users' . $delimiter);
    }

    #[Test]
    public function quote_rejects_invalid_identifier(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SqlIdentifier::quote('invalid;name', Driver::MySQL);
    }

    /**
     * Validation runs before quoting on every engine, so no delimiter can be reached
     * with an identifier that was never checked.
     */
    #[Test]
    #[DataProvider('everyDriver')]
    public function quote_validates_before_delimiting_on_every_engine(Driver $driver): void
    {
        $this->expectException(InvalidArgumentException::class);

        SqlIdentifier::quote('users; DROP TABLE users', $driver);
    }

    /**
     * @return iterable<string, array{Driver}>
     */
    public static function everyDriver(): iterable
    {
        foreach (Driver::cases() as $driver) {
            yield $driver->value => [$driver];
        }
    }
}
