<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Domain\TableRef;
use Pulsar\Extension\Orm\Exception\QueryBuilderException;

final class TableRefTest extends TestCase
{
    #[Test]
    public function ofCreatesWithoutAlias(): void
    {
        $ref = TableRef::of('users');

        self::assertSame('users', $ref->table);
        self::assertNull($ref->alias);
    }

    #[Test]
    public function ofCreatesWithAlias(): void
    {
        $ref = TableRef::of('users', 'u');

        self::assertSame('users', $ref->table);
        self::assertSame('u', $ref->alias);
    }

    #[Test]
    public function effectiveAliasReturnsAliasWhenSet(): void
    {
        $ref = TableRef::of('users', 'u');

        self::assertSame('u', $ref->effectiveAlias());
    }

    #[Test]
    public function effectiveAliasReturnsTableWhenNoAlias(): void
    {
        $ref = TableRef::of('users');

        self::assertSame('users', $ref->effectiveAlias());
    }

    #[Test]
    public function toSqlWithAlias(): void
    {
        $ref = TableRef::of('users', 'u');

        $sql = $ref->toSql(static fn(string $id): string => '"' . $id . '"');

        self::assertSame('"users" AS "u"', $sql);
    }

    #[Test]
    public function toSqlWithoutAlias(): void
    {
        $ref = TableRef::of('users');

        $sql = $ref->toSql(static fn(string $id): string => '"' . $id . '"');

        self::assertSame('"users"', $sql);
    }

    #[Test]
    public function toSqlSkipsAliasWhenSameAsTable(): void
    {
        $ref = TableRef::of('users', 'users');

        $sql = $ref->toSql(static fn(string $id): string => '`' . $id . '`');

        self::assertSame('`users`', $sql);
    }

    #[Test]
    public function ofRejectsInvalidTable(): void
    {
        $this->expectException(QueryBuilderException::class);

        (void) TableRef::of('bad-table');
    }

    #[Test]
    public function ofRejectsInvalidAlias(): void
    {
        $this->expectException(QueryBuilderException::class);

        (void) TableRef::of('users', '1bad');
    }
}
