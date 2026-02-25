<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Domain\ColumnType;

final class ColumnTypeTest extends TestCase
{
    #[Test]
    public function allCasesHaveStringBackingValues(): void
    {
        $expectedCases = [
            'String' => 'string',
            'Integer' => 'integer',
            'Float' => 'float',
            'Boolean' => 'boolean',
            'Text' => 'text',
            'Binary' => 'binary',
            'DateTime' => 'datetime',
            'Date' => 'date',
            'Time' => 'time',
            'Json' => 'json',
            'Uuid' => 'uuid',
            'Decimal' => 'decimal',
            'SmallInt' => 'smallint',
            'BigInt' => 'bigint',
            'Enum' => 'enum',
        ];

        foreach ($expectedCases as $name => $value) {
            $case = ColumnType::from($value);
            self::assertSame($value, $case->value, "Case {$name} should have value {$value}");
        }
    }

    #[Test]
    public function totalCaseCount(): void
    {
        self::assertCount(15, ColumnType::cases());
    }
}
