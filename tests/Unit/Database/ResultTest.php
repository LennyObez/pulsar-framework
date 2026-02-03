<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Exception\DatabaseException;
use Pulsar\Database\Result;
use Pulsar\Database\Row;

#[CoversClass(Result::class)]
final class ResultTest extends TestCase
{
    #[Test]
    public function constructorSetsRowCountCorrectly(): void
    {
        $result = new Result([
            new Row(['id' => 1]),
            new Row(['id' => 2]),
        ]);

        self::assertSame(2, $result->rowCount);
    }

    #[Test]
    public function emptyResultHasZeroRowCount(): void
    {
        $result = new Result([]);

        self::assertSame(0, $result->rowCount);
    }

    #[Test]
    public function fromArraysCreatesResultFromRawData(): void
    {
        $result = Result::fromArrays([
            ['id' => 1, 'name' => 'Alice'],
            ['id' => 2, 'name' => 'Bob'],
        ]);

        self::assertSame(2, $result->rowCount);
        self::assertSame('Alice', $result->rows[0]->getString('name'));
        self::assertSame('Bob', $result->rows[1]->getString('name'));
    }

    #[Test]
    public function firstReturnsFirstRow(): void
    {
        $result = Result::fromArrays([
            ['id' => 1, 'name' => 'Alice'],
            ['id' => 2, 'name' => 'Bob'],
        ]);

        $first = $result->first();

        self::assertNotNull($first);
        self::assertSame('Alice', $first->getString('name'));
    }

    #[Test]
    public function firstReturnsNullForEmptyResult(): void
    {
        $result = new Result([]);

        self::assertNull($result->first());
    }

    #[Test]
    public function firstOrFailReturnsFirstRow(): void
    {
        $result = Result::fromArrays([
            ['id' => 1, 'name' => 'Alice'],
        ]);

        $first = $result->firstOrFail();

        self::assertSame('Alice', $first->getString('name'));
    }

    #[Test]
    public function firstOrFailThrowsForEmptyResult(): void
    {
        $result = new Result([]);

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('Query returned an empty result set');

        $result->firstOrFail();
    }

    #[Test]
    public function pluckExtractsSingleColumn(): void
    {
        $result = Result::fromArrays([
            ['id' => 1, 'name' => 'Alice'],
            ['id' => 2, 'name' => 'Bob'],
            ['id' => 3, 'name' => 'Charlie'],
        ]);

        self::assertSame(['Alice', 'Bob', 'Charlie'], $result->pluck('name'));
    }

    #[Test]
    public function isEmptyReturnsTrueForEmptyResult(): void
    {
        $result = new Result([]);

        self::assertTrue($result->isEmpty());
    }

    #[Test]
    public function isEmptyReturnsFalseForNonEmptyResult(): void
    {
        $result = Result::fromArrays([['id' => 1]]);

        self::assertFalse($result->isEmpty());
    }

    #[Test]
    public function mapTransformsRows(): void
    {
        $result = Result::fromArrays([
            ['id' => 1, 'name' => 'Alice'],
            ['id' => 2, 'name' => 'Bob'],
        ]);

        $names = $result->map(fn(Row $row): string => $row->getString('name'));

        self::assertSame(['Alice', 'Bob'], $names);
    }

    #[Test]
    public function mapWithEmptyResultReturnsEmptyArray(): void
    {
        $result = new Result([]);

        $mapped = $result->map(fn(Row $row): string => $row->getString('name'));

        self::assertSame([], $mapped);
    }
}
