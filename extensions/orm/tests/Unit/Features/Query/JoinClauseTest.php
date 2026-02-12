<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Features\Query;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Features\Query\JoinClause;

final class JoinClauseTest extends TestCase
{
    #[Test]
    public function toSqlFormatsCorrectly(): void
    {
        $join = new JoinClause(
            type: 'INNER',
            table: '"posts" AS "p"',
            condition: '"p"."user_id" = "u"."id"',
        );

        self::assertSame('INNER JOIN "posts" AS "p" ON "p"."user_id" = "u"."id"', $join->toSql());
    }

    #[Test]
    public function leftJoinType(): void
    {
        $join = new JoinClause(
            type: 'LEFT',
            table: '`comments` AS `c`',
            condition: '`c`.`post_id` = `p`.`id`',
        );

        self::assertStringStartsWith('LEFT JOIN', $join->toSql());
    }

    #[Test]
    public function storesBindings(): void
    {
        $join = new JoinClause(
            type: 'INNER',
            table: '"t"',
            condition: '"t"."x" = :p0',
            bindings: ['p0' => 'val'],
        );

        self::assertSame(['p0' => 'val'], $join->bindings);
    }
}
