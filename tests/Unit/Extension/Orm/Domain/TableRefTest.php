<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Orm\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Domain\TableRef;
use Pulsar\Extension\Orm\Exception\QueryBuilderException;

#[CoversClass(TableRef::class)]
final class TableRefTest extends TestCase
{
    #[Test]
    public function ofWithTableNameOnly(): void
    {
        $ref = TableRef::of('users');

        self::assertSame('users', $ref->table);
        self::assertNull($ref->alias);
        self::assertSame('users', $ref->effectiveAlias());
    }

    #[Test]
    public function ofWithAlias(): void
    {
        $ref = TableRef::of('users', 'u');

        self::assertSame('users', $ref->table);
        self::assertSame('u', $ref->alias);
        self::assertSame('u', $ref->effectiveAlias());
    }

    #[Test]
    public function ofRejectsInvalidTableName(): void
    {
        $this->expectException(QueryBuilderException::class);

        $_ = TableRef::of('bad-table');
    }

    #[Test]
    public function ofRejectsInvalidAlias(): void
    {
        $this->expectException(QueryBuilderException::class);

        $_ = TableRef::of('users', 'bad-alias');
    }

    #[Test]
    public function toSqlWithoutAlias(): void
    {
        $ref = TableRef::of('users');
        $quoter = static fn(string $id): string => '`' . $id . '`';

        self::assertSame('`users`', $ref->toSql($quoter));
    }

    #[Test]
    public function toSqlWithAlias(): void
    {
        $ref = TableRef::of('users', 'u');
        $quoter = static fn(string $id): string => '`' . $id . '`';

        self::assertSame('`users` AS `u`', $ref->toSql($quoter));
    }

    #[Test]
    public function toSqlWhenAliasMatchesTable(): void
    {
        $ref = TableRef::of('users', 'users');
        $quoter = static fn(string $id): string => '`' . $id . '`';

        self::assertSame('`users`', $ref->toSql($quoter));
    }
}
