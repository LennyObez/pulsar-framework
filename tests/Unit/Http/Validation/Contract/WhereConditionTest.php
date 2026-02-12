<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Contract;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Contract\ColumnName;
use Pulsar\Http\Validation\Contract\WhereCondition;

#[CoversClass(WhereCondition::class)]
final class WhereConditionTest extends TestCase
{
    #[Test]
    public function constructorSetsProperties(): void
    {
        $column = new ColumnName('status');
        $condition = new WhereCondition($column, 'active');

        self::assertSame($column, $condition->column);
        self::assertSame('active', $condition->value);
    }

    #[Test]
    public function acceptsMixedValues(): void
    {
        $column = new ColumnName('id');

        $intCondition = new WhereCondition($column, 42);
        self::assertSame(42, $intCondition->value);

        $nullCondition = new WhereCondition($column, null);
        self::assertNull($nullCondition->value);
    }
}
