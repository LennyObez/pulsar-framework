<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Schema\SchemaColumnType;

#[CoversClass(SchemaColumnType::class)]
final class SchemaColumnTypeTest extends TestCase
{
    #[Test]
    public function allCasesHaveStringBackingValues(): void
    {
        foreach (SchemaColumnType::cases() as $case) {
            self::assertNotEmpty($case->value);
        }
    }

    #[Test]
    public function canCreateFromValidString(): void
    {
        self::assertSame(SchemaColumnType::Integer, SchemaColumnType::from('integer'));
        self::assertSame(SchemaColumnType::String, SchemaColumnType::from('string'));
        self::assertSame(SchemaColumnType::Boolean, SchemaColumnType::from('boolean'));
    }

    #[Test]
    public function hasFifteenCases(): void
    {
        self::assertCount(15, SchemaColumnType::cases());
    }
}
