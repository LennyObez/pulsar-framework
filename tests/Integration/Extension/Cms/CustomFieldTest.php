<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Extension\Cms;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\FieldRegistry\ContentFieldValue;
use Pulsar\Extension\Cms\FieldRegistry\ContentTypeBuilder;
use Pulsar\Extension\Cms\FieldRegistry\ContentTypeDefinition;
use Pulsar\Extension\Cms\FieldRegistry\ContentTypeField;
use Pulsar\Extension\Cms\FieldRegistry\ContentTypeRegistryInterface;
use Pulsar\Extension\Cms\FieldRegistry\FieldRegistryRepositoryInterface;
use Pulsar\Extension\Cms\FieldRegistry\FieldType;

#[CoversClass(ContentTypeBuilder::class)]
#[CoversClass(ContentTypeDefinition::class)]
#[CoversClass(ContentTypeField::class)]
#[CoversClass(ContentFieldValue::class)]
final class CustomFieldTest extends TestCase
{
    private InMemoryFieldRegistryRepository $fieldRepo;
    private InMemoryContentTypeRegistry $typeRegistry;

    protected function setUp(): void
    {
        $this->fieldRepo = new InMemoryFieldRegistryRepository();
        $this->typeRegistry = new InMemoryContentTypeRegistry();
    }

    #[Test]
    public function registerContentTypeWithFields(): void
    {
        $definition = new ContentTypeBuilder('project')
            ->label('Project')
            ->icon('code')
            ->field('tagline', FieldType::String, required: true, translatable: true, searchable: true)
            ->field('featured', FieldType::Bool, default: false)
            ->field('release-date', FieldType::Date)
            ->field('budget', FieldType::Float, filterable: true, sortable: true)
            ->build();

        $this->typeRegistry->register($definition);

        $found = $this->typeRegistry->get('project');
        self::assertNotNull($found);
        self::assertSame('Project', $found->label);
        self::assertSame('code', $found->icon);
        self::assertCount(4, $found->fields);

        // Verify field properties
        $tagline = $found->fields[0];
        self::assertSame('tagline', $tagline->fieldKey);
        self::assertSame(FieldType::String, $tagline->fieldType);
        self::assertTrue($tagline->required);
        self::assertTrue($tagline->translatable);
        self::assertTrue($tagline->searchable);

        $featured = $found->fields[1];
        self::assertSame('featured', $featured->fieldKey);
        self::assertSame(FieldType::Bool, $featured->fieldType);
        self::assertFalse($featured->required);
        self::assertFalse($featured->defaultValue);
    }

    #[Test]
    public function saveAndRetrieveFieldValues(): void
    {
        // Register a field definition
        $field = new ContentTypeField(
            id: 'field-001',
            contentType: 'project',
            fieldKey: 'tagline',
            fieldType: FieldType::String,
            required: true,
            translatable: true,
            searchable: true,
            filterable: false,
            sortable: false,
            validationRules: ['max_length' => 200],
            defaultValue: null,
            sortOrder: 0,
        );
        $this->fieldRepo->saveField($field);

        // Save a field value
        $value = new ContentFieldValue(
            id: 'value-001',
            contentId: 'content-001',
            fieldId: 'field-001',
            locale: 'en',
            valueString: 'Build amazing things',
            valueInt: null,
            valueFloat: null,
            valueBool: null,
            valueDatetime: null,
            valueJson: null,
        );
        $this->fieldRepo->saveValue($value);

        // Retrieve values
        $values = $this->fieldRepo->findValues('content-001', 'en');
        self::assertCount(1, $values);
        self::assertSame('Build amazing things', $values[0]->valueString);
        self::assertSame('field-001', $values[0]->fieldId);
        self::assertSame('en', $values[0]->locale);
    }

    #[Test]
    public function fieldTypeValueColumnMapping(): void
    {
        // Verify each field type maps to the correct storage column
        self::assertSame('value_string', FieldType::String->valueColumn());
        self::assertSame('value_string', FieldType::Enum->valueColumn());
        self::assertSame('value_string', FieldType::Url->valueColumn());
        self::assertSame('value_string', FieldType::Email->valueColumn());
        self::assertSame('value_string', FieldType::Color->valueColumn());
        self::assertSame('value_int', FieldType::Int->valueColumn());
        self::assertSame('value_float', FieldType::Float->valueColumn());
        self::assertSame('value_bool', FieldType::Bool->valueColumn());
        self::assertSame('value_datetime', FieldType::Date->valueColumn());
        self::assertSame('value_datetime', FieldType::DateTime->valueColumn());
        self::assertSame('value_json', FieldType::Json->valueColumn());
        self::assertSame('value_json', FieldType::Relation->valueColumn());
        self::assertSame('value_json', FieldType::Media->valueColumn());
        self::assertSame('value_json', FieldType::RichText->valueColumn());

        // Test that different field types produce different value objects
        $stringValue = new ContentFieldValue(
            id: 'sv-1',
            contentId: 'c-1',
            fieldId: 'f-1',
            locale: 'en',
            valueString: 'hello',
            valueInt: null,
            valueFloat: null,
            valueBool: null,
            valueDatetime: null,
            valueJson: null,
        );
        self::assertSame('hello', $stringValue->valueString);
        self::assertNull($stringValue->valueInt);

        $intValue = new ContentFieldValue(
            id: 'sv-2',
            contentId: 'c-1',
            fieldId: 'f-2',
            locale: 'en',
            valueString: null,
            valueInt: 42,
            valueFloat: null,
            valueBool: null,
            valueDatetime: null,
            valueJson: null,
        );
        self::assertNull($intValue->valueString);
        self::assertSame(42, $intValue->valueInt);

        $boolValue = new ContentFieldValue(
            id: 'sv-3',
            contentId: 'c-1',
            fieldId: 'f-3',
            locale: 'en',
            valueString: null,
            valueInt: null,
            valueFloat: null,
            valueBool: true,
            valueDatetime: null,
            valueJson: null,
        );
        self::assertTrue($boolValue->valueBool);

        $jsonValue = new ContentFieldValue(
            id: 'sv-4',
            contentId: 'c-1',
            fieldId: 'f-4',
            locale: 'en',
            valueString: null,
            valueInt: null,
            valueFloat: null,
            valueBool: null,
            valueDatetime: null,
            valueJson: ['key' => 'value'],
        );
        self::assertSame(['key' => 'value'], $jsonValue->valueJson);
    }
}

final class InMemoryFieldRegistryRepository implements FieldRegistryRepositoryInterface
{
    /** @var array<string, ContentTypeField> */
    private array $fields = [];

    /** @var array<string, ContentFieldValue> */
    private array $values = [];

    public function findFieldsByContentType(string $contentType): array
    {
        return array_values(
            array_filter(
                $this->fields,
                static fn(ContentTypeField $f) => $f->contentType === $contentType,
            ),
        );
    }

    public function saveField(ContentTypeField $field): void
    {
        $this->fields[$field->id] = $field;
    }

    public function saveValue(ContentFieldValue $value): void
    {
        $this->values[$value->id] = $value;
    }

    public function findValues(string $contentId, ?string $locale = null): array
    {
        return array_values(
            array_filter(
                $this->values,
                static fn(ContentFieldValue $v) => $v->contentId === $contentId
                    && ($locale === null || $v->locale === $locale),
            ),
        );
    }
}

final class InMemoryContentTypeRegistry implements ContentTypeRegistryInterface
{
    /** @var array<string, ContentTypeDefinition> */
    private array $definitions = [];

    public function register(ContentTypeDefinition $definition): void
    {
        $this->definitions[$definition->type] = $definition;
    }

    public function get(string $type): ?ContentTypeDefinition
    {
        return $this->definitions[$type] ?? null;
    }

    public function all(): array
    {
        return $this->definitions;
    }
}
