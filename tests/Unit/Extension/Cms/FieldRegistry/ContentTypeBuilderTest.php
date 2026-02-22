<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\FieldRegistry;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\FieldRegistry\ContentTypeBuilder;
use Pulsar\Extension\Cms\FieldRegistry\ContentTypeDefinition;
use Pulsar\Extension\Cms\FieldRegistry\FieldType;

#[CoversClass(ContentTypeBuilder::class)]
#[CoversClass(ContentTypeDefinition::class)]
final class ContentTypeBuilderTest extends TestCase
{
    #[Test]
    public function buildReturnsContentTypeDefinition(): void
    {
        $builder = new ContentTypeBuilder('project');
        $definition = $builder
            ->label('Project')
            ->icon('code')
            ->build();

        self::assertInstanceOf(ContentTypeDefinition::class, $definition);
        self::assertSame('project', $definition->type);
        self::assertSame('Project', $definition->label);
        self::assertSame('code', $definition->icon);
        self::assertSame([], $definition->fields);
    }

    #[Test]
    public function fluentApiChainsCorrectly(): void
    {
        $builder = new ContentTypeBuilder('recipe');
        $result = $builder->label('Recipe')->icon('utensils');

        self::assertSame($builder, $result);
    }

    #[Test]
    public function fieldAddsFieldToDefinition(): void
    {
        $definition = new ContentTypeBuilder('product')
            ->label('Product')
            ->icon('box')
            ->field('price', FieldType::Float, required: true)
            ->field('sku', FieldType::String, required: true, filterable: true)
            ->build();

        self::assertCount(2, $definition->fields);
        self::assertSame('price', $definition->fields[0]->fieldKey);
        self::assertSame(FieldType::Float, $definition->fields[0]->fieldType);
        self::assertTrue($definition->fields[0]->required);
        self::assertSame(0, $definition->fields[0]->sortOrder);

        self::assertSame('sku', $definition->fields[1]->fieldKey);
        self::assertSame(FieldType::String, $definition->fields[1]->fieldType);
        self::assertTrue($definition->fields[1]->filterable);
        self::assertSame(1, $definition->fields[1]->sortOrder);
    }

    #[Test]
    public function all14FieldTypesRegisterCorrectly(): void
    {
        $builder = new ContentTypeBuilder('mega');
        $builder->label('Mega Type')->icon('star');

        foreach (FieldType::cases() as $i => $type) {
            $builder->field("field_{$type->value}", $type);
        }

        $definition = $builder->build();

        self::assertCount(14, $definition->fields);

        // Verify each field type is present
        $fieldTypes = array_map(
            static fn($field) => $field->fieldType,
            $definition->fields,
        );

        foreach (FieldType::cases() as $type) {
            self::assertContains($type, $fieldTypes);
        }
    }

    #[Test]
    public function fieldSortOrderAutoIncrements(): void
    {
        $definition = new ContentTypeBuilder('test')
            ->label('Test')
            ->icon('test')
            ->field('first', FieldType::String)
            ->field('second', FieldType::Int)
            ->field('third', FieldType::Bool)
            ->build();

        self::assertSame(0, $definition->fields[0]->sortOrder);
        self::assertSame(1, $definition->fields[1]->sortOrder);
        self::assertSame(2, $definition->fields[2]->sortOrder);
    }

    #[Test]
    public function fieldOptionsAreSetCorrectly(): void
    {
        $definition = new ContentTypeBuilder('article')
            ->label('Article')
            ->icon('file')
            ->field(
                'tagline',
                FieldType::String,
                required: true,
                translatable: true,
                searchable: true,
                filterable: false,
                sortable: true,
                validation: ['max_length' => 200],
                default: 'Default tagline',
            )
            ->build();

        $field = $definition->fields[0];

        self::assertSame('tagline', $field->fieldKey);
        self::assertTrue($field->required);
        self::assertTrue($field->translatable);
        self::assertTrue($field->searchable);
        self::assertFalse($field->filterable);
        self::assertTrue($field->sortable);
        self::assertSame(['max_length' => 200], $field->validationRules);
        self::assertSame('Default tagline', $field->defaultValue);
    }

    #[Test]
    public function fieldContentTypeMatchesBuilderType(): void
    {
        $definition = new ContentTypeBuilder('custom')
            ->label('Custom')
            ->icon('cube')
            ->field('name', FieldType::String)
            ->build();

        self::assertSame('custom', $definition->fields[0]->contentType);
    }

    #[Test]
    public function buildWithNoLabelOrIcon(): void
    {
        $definition = new ContentTypeBuilder('bare')->build();

        self::assertSame('bare', $definition->type);
        self::assertSame('', $definition->label);
        self::assertSame('', $definition->icon);
    }
}
