<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Portable;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Driver;
use Pulsar\Database\Portable\InListBuilder;

#[CoversClass(InListBuilder::class)]
final class InListBuilderTest extends TestCase
{
    // --- compile() ---

    #[Test]
    public function postgresqlUsesAnyClause(): void
    {
        $sql = InListBuilder::compile(Driver::PostgreSQL, 'id', 'ids', 3);

        self::assertSame('id = ANY(:ids)', $sql);
    }

    #[Test]
    public function mysqlUsesInListWithIndexedParams(): void
    {
        $sql = InListBuilder::compile(Driver::MySQL, 'id', 'ids', 3);

        self::assertSame('id IN (:ids_0, :ids_1, :ids_2)', $sql);
    }

    #[Test]
    public function sqliteUsesInListWithIndexedParams(): void
    {
        $sql = InListBuilder::compile(Driver::SQLite, 'id', 'ids', 2);

        self::assertSame('id IN (:ids_0, :ids_1)', $sql);
    }

    #[Test]
    public function compileSingleValueMysql(): void
    {
        $sql = InListBuilder::compile(Driver::MySQL, 'status', 'statuses', 1);

        self::assertSame('status IN (:statuses_0)', $sql);
    }

    #[Test]
    public function compileSingleValuePostgresql(): void
    {
        $sql = InListBuilder::compile(Driver::PostgreSQL, 'status', 'statuses', 1);

        self::assertSame('status = ANY(:statuses)', $sql);
    }

    #[Test]
    #[DataProvider('driverProvider')]
    public function compileThrowsOnZeroCount(Driver $driver): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at least one value');

        InListBuilder::compile($driver, 'id', 'ids', 0);
    }

    #[Test]
    #[DataProvider('driverProvider')]
    public function compileThrowsOnNegativeCount(Driver $driver): void
    {
        $this->expectException(InvalidArgumentException::class);

        InListBuilder::compile($driver, 'id', 'ids', -1);
    }

    // --- expandParams() ---

    #[Test]
    public function expandParamsPostgresqlCreatesArrayLiteral(): void
    {
        $bindings = InListBuilder::expandParams(
            Driver::PostgreSQL,
            'ids',
            ['abc-123', 'def-456', 'ghi-789'],
        );

        self::assertSame(['ids' => '{abc-123,def-456,ghi-789}'], $bindings);
    }

    #[Test]
    public function expandParamsMysqlCreatesIndexedBindings(): void
    {
        $bindings = InListBuilder::expandParams(
            Driver::MySQL,
            'ids',
            ['abc-123', 'def-456'],
        );

        self::assertSame([
            'ids_0' => 'abc-123',
            'ids_1' => 'def-456',
        ], $bindings);
    }

    #[Test]
    public function expandParamsSqliteCreatesIndexedBindings(): void
    {
        $bindings = InListBuilder::expandParams(
            Driver::SQLite,
            'tags',
            ['alpha'],
        );

        self::assertSame(['tags_0' => 'alpha'], $bindings);
    }

    #[Test]
    #[DataProvider('driverProvider')]
    public function expandParamsThrowsOnEmptyValues(Driver $driver): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at least one value');

        InListBuilder::expandParams($driver, 'ids', []);
    }

    #[Test]
    public function compileAndExpandAreConsistentForMysql(): void
    {
        $values = ['a', 'b', 'c'];

        $sql = InListBuilder::compile(Driver::MySQL, 'col', 'p', count($values));
        $bindings = InListBuilder::expandParams(Driver::MySQL, 'p', $values);

        // SQL should have exactly as many placeholders as bindings
        self::assertCount(3, $bindings);
        self::assertStringContainsString(':p_0', $sql);
        self::assertStringContainsString(':p_1', $sql);
        self::assertStringContainsString(':p_2', $sql);

        // Binding keys should match placeholder names (without colon)
        foreach ($bindings as $key => $value) {
            self::assertStringContainsString(':' . $key, $sql);
        }
    }

    #[Test]
    public function compileAndExpandAreConsistentForPostgresql(): void
    {
        $values = ['x', 'y'];

        $sql = InListBuilder::compile(Driver::PostgreSQL, 'col', 'items', count($values));
        $bindings = InListBuilder::expandParams(Driver::PostgreSQL, 'items', $values);

        // PostgreSQL uses a single parameter
        self::assertCount(1, $bindings);
        self::assertArrayHasKey('items', $bindings);
        self::assertStringContainsString(':items', $sql);
    }

    /**
     * @return iterable<string, array{Driver}>
     */
    public static function driverProvider(): iterable
    {
        yield 'MySQL' => [Driver::MySQL];
        yield 'PostgreSQL' => [Driver::PostgreSQL];
        yield 'SQLite' => [Driver::SQLite];
    }
}
