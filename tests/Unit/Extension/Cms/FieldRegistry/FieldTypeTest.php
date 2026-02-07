<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\FieldRegistry;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\FieldRegistry\FieldType;

#[CoversClass(FieldType::class)]
final class FieldTypeTest extends TestCase
{
    #[Test]
    #[DataProvider('valueColumnMappingProvider')]
    public function test_value_column_returns_correct_column(FieldType $type, string $expectedColumn): void
    {
        self::assertSame($expectedColumn, $type->valueColumn());
    }

    /**
     * @return iterable<string, array{FieldType, string}>
     */
    public static function valueColumnMappingProvider(): iterable
    {
        yield 'String' => [FieldType::String, 'value_string'];
        yield 'Int' => [FieldType::Int, 'value_int'];
        yield 'Float' => [FieldType::Float, 'value_float'];
        yield 'Bool' => [FieldType::Bool, 'value_bool'];
        yield 'Date' => [FieldType::Date, 'value_datetime'];
        yield 'DateTime' => [FieldType::DateTime, 'value_datetime'];
        yield 'Enum' => [FieldType::Enum, 'value_string'];
        yield 'Relation' => [FieldType::Relation, 'value_json'];
        yield 'Json' => [FieldType::Json, 'value_json'];
        yield 'Media' => [FieldType::Media, 'value_json'];
        yield 'RichText' => [FieldType::RichText, 'value_json'];
        yield 'Color' => [FieldType::Color, 'value_string'];
        yield 'Url' => [FieldType::Url, 'value_string'];
        yield 'Email' => [FieldType::Email, 'value_string'];
    }

    #[Test]
    public function test_all_14_field_types_exist(): void
    {
        self::assertCount(14, FieldType::cases());
    }

    #[Test]
    public function test_all_field_types_have_string_backing_values(): void
    {
        foreach (FieldType::cases() as $type) {
            self::assertNotEmpty($type->value);
        }
    }

    #[Test]
    public function test_from_valid_value(): void
    {
        self::assertSame(FieldType::String, FieldType::from('string'));
        self::assertSame(FieldType::Json, FieldType::from('json'));
        self::assertSame(FieldType::RichText, FieldType::from('rich_text'));
    }

    #[Test]
    public function test_try_from_invalid_value_returns_null(): void
    {
        self::assertNull(FieldType::tryFrom('nonexistent'));
    }
}
