<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Schema;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Schema\SchemaColumnType;

#[CoversNothing]
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
        self::assertSame(SchemaColumnType::Double, SchemaColumnType::from('double'));
    }

    #[Test]
    public function hasSeventeenCases(): void
    {
        self::assertCount(17, SchemaColumnType::cases());
    }

    /**
     * The narrow and the wide text types are separate cases, and stay separate.
     *
     * Folding them back into one is how the layer lost MySQL's LONGTEXT in the first
     * place: a single `Text` case compiled to `TEXT` on every engine, which on MySQL is
     * 65,535 bytes and silently too small for a serialised payload.
     */
    #[Test]
    public function narrowAndWideTextAreDistinctCases(): void
    {
        self::assertSame(SchemaColumnType::Text, SchemaColumnType::from('text'));
        self::assertSame(SchemaColumnType::BigText, SchemaColumnType::from('bigtext'));
        self::assertNotSame(SchemaColumnType::Text, SchemaColumnType::BigText);
    }
}
