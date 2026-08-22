<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Contract;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Contract\ColumnName;
use Pulsar\Http\Validation\Contract\InvalidIdentifierException;

#[CoversClass(ColumnName::class)]
#[CoversClass(InvalidIdentifierException::class)]
final class ColumnNameTest extends TestCase
{
    #[Test]
    public function validColumnName(): void
    {
        $name = new ColumnName('email');

        self::assertSame('email', $name->value);
    }

    #[Test]
    public function validColumnNameWithUnderscore(): void
    {
        $name = new ColumnName('created_at');

        self::assertSame('created_at', $name->value);
    }

    #[Test]
    #[DataProvider('invalidColumnProvider')]
    public function rejectsInvalidColumn(string $invalid): void
    {
        $this->expectException(InvalidIdentifierException::class);

        new ColumnName($invalid);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidColumnProvider(): iterable
    {
        yield 'empty string' => [''];
        yield 'contains spaces' => ['first name'];
        yield 'starts with number' => ['1column'];
        yield 'SQL keyword SELECT' => ['SELECT'];
        yield 'SQL keyword DROP' => ['DROP'];
    }

    #[Test]
    public function exceptionMessageContainsColumn(): void
    {
        $this->expectException(InvalidIdentifierException::class);
        $this->expectExceptionMessageIsOrContains('Invalid column name: "SELECT"');

        new ColumnName('SELECT');
    }
}
