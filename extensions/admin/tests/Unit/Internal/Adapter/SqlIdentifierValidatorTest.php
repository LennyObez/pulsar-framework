<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Internal\Adapter;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests the SqlIdentifierValidator trait via a test harness.
 */
#[CoversClass(\Pulsar\Extension\Admin\Internal\Adapter\SqlIdentifierValidator::class)]
final class SqlIdentifierValidatorTest extends TestCase
{
    #[Test]
    #[DataProvider('validIdentifierProvider')]
    public function valid_identifier_returns_backtick_quoted(string $identifier, string $expected): void
    {
        $harness = new class {
            use \Pulsar\Extension\Admin\Internal\Adapter\SqlIdentifierValidator;

            public function quote(string $id): string
            {
                return $this->quoteIdentifier($id);
            }
        };

        self::assertSame($expected, $harness->quote($identifier));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function validIdentifierProvider(): iterable
    {
        yield 'simple' => ['users', '`users`'];
        yield 'underscore prefix' => ['_internal', '`_internal`'];
        yield 'mixed case' => ['UserRoles', '`UserRoles`'];
        yield 'with numbers' => ['table_123', '`table_123`'];
        yield 'single char' => ['a', '`a`'];
    }

    #[Test]
    #[DataProvider('invalidIdentifierProvider')]
    public function invalid_identifier_throws(string $identifier): void
    {
        $harness = new class {
            use \Pulsar\Extension\Admin\Internal\Adapter\SqlIdentifierValidator;

            public function quote(string $id): string
            {
                return $this->quoteIdentifier($id);
            }
        };

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Invalid SQL identifier/');

        $harness->quote($identifier);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidIdentifierProvider(): iterable
    {
        yield 'empty string' => [''];
        yield 'starts with number' => ['1table'];
        yield 'contains space' => ['user name'];
        yield 'contains dash' => ['user-name'];
        yield 'contains dot' => ['schema.table'];
        yield 'sql injection attempt' => ['users; DROP TABLE'];
        yield 'backtick injection' => ['users`; --'];
        yield 'too long' => [str_repeat('a', 129)];
    }

    #[Test]
    public function max_length_identifier_is_valid(): void
    {
        $harness = new class {
            use \Pulsar\Extension\Admin\Internal\Adapter\SqlIdentifierValidator;

            public function quote(string $id): string
            {
                return $this->quoteIdentifier($id);
            }
        };

        $identifier = 'a' . str_repeat('b', 127);
        self::assertSame("`$identifier`", $harness->quote($identifier));
    }
}
