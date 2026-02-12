<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Contract;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Contract\ColumnName;
use Pulsar\Http\Validation\Contract\WhereCondition;
use Pulsar\Http\Validation\Contract\WhereConditions;

#[CoversClass(WhereConditions::class)]
final class WhereConditionsTest extends TestCase
{
    #[Test]
    public function emptyByDefault(): void
    {
        $conditions = new WhereConditions();

        self::assertTrue($conditions->isEmpty());
        self::assertSame([], $conditions->conditions);
    }

    #[Test]
    public function holdsConditions(): void
    {
        $c1 = new WhereCondition(new ColumnName('status'), 'active');
        $c2 = new WhereCondition(new ColumnName('role'), 'admin');

        $conditions = new WhereConditions([$c1, $c2]);

        self::assertFalse($conditions->isEmpty());
        self::assertCount(2, $conditions->conditions);
        self::assertSame($c1, $conditions->conditions[0]);
        self::assertSame($c2, $conditions->conditions[1]);
    }

    #[Test]
    public function reindexesNonListArrays(): void
    {
        $c1 = new WhereCondition(new ColumnName('status'), 'active');

        $conditions = new WhereConditions([5 => $c1]);

        self::assertSame([$c1], $conditions->conditions);
    }
}
