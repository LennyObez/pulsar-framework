<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Admin\Internal\Adapter;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Internal\Adapter\SqlIdentifierValidator;

final class SqlIdentifierValidatorTest extends TestCase
{
    use SqlIdentifierValidator {
        quoteIdentifier as public;
    }

    #[Test]
    public function validSimpleIdentifier(): void
    {
        self::assertSame('`users`', $this->quoteIdentifier('users'));
    }

    #[Test]
    public function validIdentifierWithUnderscore(): void
    {
        self::assertSame('`user_roles`', $this->quoteIdentifier('user_roles'));
    }

    #[Test]
    public function validIdentifierStartingWithUnderscore(): void
    {
        self::assertSame('`_internal`', $this->quoteIdentifier('_internal'));
    }

    #[Test]
    public function validSingleCharIdentifier(): void
    {
        self::assertSame('`a`', $this->quoteIdentifier('a'));
    }

    #[Test]
    public function validMaxLengthIdentifier(): void
    {
        $ident = 'a' . str_repeat('b', 127);
        self::assertSame("`$ident`", $this->quoteIdentifier($ident));
    }

    #[Test]
    public function rejectsEmptyString(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->quoteIdentifier('');
    }

    #[Test]
    public function rejectsIdentifierStartingWithDigit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->quoteIdentifier('1table');
    }

    #[Test]
    public function rejectsIdentifierWithSpecialChars(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->quoteIdentifier('table-name');
    }

    #[Test]
    public function rejectsIdentifierWithSpaces(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->quoteIdentifier('table name');
    }

    #[Test]
    public function rejectsIdentifierWithSemicolon(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->quoteIdentifier('table;DROP');
    }

    #[Test]
    public function rejectsIdentifierWithBacktick(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->quoteIdentifier('table`name');
    }

    #[Test]
    public function rejectsTooLongIdentifier(): void
    {
        $ident = 'a' . str_repeat('b', 128); // 129 chars
        $this->expectException(InvalidArgumentException::class);
        $this->quoteIdentifier($ident);
    }

    #[Test]
    public function rejectsDotSeparatedIdentifier(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->quoteIdentifier('schema.table');
    }
}
