<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Domain\FieldType;

use function count;

#[CoversNothing]
final class FieldTypeTest extends TestCase
{
    #[Test]
    public function hasAtLeast30FieldTypes(): void
    {
        self::assertGreaterThanOrEqual(30, count(FieldType::cases()));
    }

    #[Test]
    #[DataProvider('editableProvider')]
    public function isEditableDistinguishesInputFields(FieldType $type, bool $expected): void
    {
        self::assertSame($expected, $type->isEditable());
    }

    /**
     * @return iterable<string, array{FieldType, bool}>
     */
    public static function editableProvider(): iterable
    {
        yield 'string is editable' => [FieldType::String, true];
        yield 'rich text is editable' => [FieldType::RichText, true];
        yield 'color is editable' => [FieldType::Color, true];
        yield 'hidden is not editable' => [FieldType::Hidden, false];
        yield 'computed is not editable' => [FieldType::Computed, false];
    }

    #[Test]
    #[DataProvider('searchableProvider')]
    public function isSearchableIdentifiesTextFields(FieldType $type, bool $expected): void
    {
        self::assertSame($expected, $type->isSearchable());
    }

    /**
     * @return iterable<string, array{FieldType, bool}>
     */
    public static function searchableProvider(): iterable
    {
        yield 'string is searchable' => [FieldType::String, true];
        yield 'text is searchable' => [FieldType::Text, true];
        yield 'rich text is searchable' => [FieldType::RichText, true];
        yield 'email is searchable' => [FieldType::Email, true];
        yield 'tags is searchable' => [FieldType::Tags, true];
        yield 'integer is not searchable' => [FieldType::Integer, false];
        yield 'boolean is not searchable' => [FieldType::Boolean, false];
        yield 'json is not searchable' => [FieldType::Json, false];
    }

    #[Test]
    #[DataProvider('sortableProvider')]
    public function isSortableIdentifiesOrderableFields(FieldType $type, bool $expected): void
    {
        self::assertSame($expected, $type->isSortable());
    }

    /**
     * @return iterable<string, array{FieldType, bool}>
     */
    public static function sortableProvider(): iterable
    {
        yield 'string is sortable' => [FieldType::String, true];
        yield 'integer is sortable' => [FieldType::Integer, true];
        yield 'date is sortable' => [FieldType::Date, true];
        yield 'rating is sortable' => [FieldType::Rating, true];
        yield 'json is not sortable' => [FieldType::Json, false];
        yield 'repeater is not sortable' => [FieldType::Repeater, false];
        yield 'file upload is not sortable' => [FieldType::FileUpload, false];
    }

    #[Test]
    public function allFieldTypesHaveUniqueStringValues(): void
    {
        $values = [];

        foreach (FieldType::cases() as $type) {
            self::assertNotContains($type->value, $values, "Duplicate value: {$type->value}");
            $values[] = $type->value;
        }
    }

    #[Test]
    public function newFieldTypesExist(): void
    {
        // Verify all new field types requested in the competitive gap analysis
        self::assertNotNull(FieldType::tryFrom('rich_text'));
        self::assertNotNull(FieldType::tryFrom('markdown'));
        self::assertNotNull(FieldType::tryFrom('date'));
        self::assertNotNull(FieldType::tryFrom('time'));
        self::assertNotNull(FieldType::tryFrom('color'));
        self::assertNotNull(FieldType::tryFrom('file_upload'));
        self::assertNotNull(FieldType::tryFrom('repeater'));
        self::assertNotNull(FieldType::tryFrom('key_value'));
        self::assertNotNull(FieldType::tryFrom('json'));
        self::assertNotNull(FieldType::tryFrom('code'));
        self::assertNotNull(FieldType::tryFrom('slug'));
        self::assertNotNull(FieldType::tryFrom('toggle'));
        self::assertNotNull(FieldType::tryFrom('rating'));
        self::assertNotNull(FieldType::tryFrom('tags'));
    }
}
