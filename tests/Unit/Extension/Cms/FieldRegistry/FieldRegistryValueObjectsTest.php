<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\FieldRegistry;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\FieldRegistry\ContentTypeDefinition;
use Pulsar\Extension\Cms\FieldRegistry\ContentTypeField;
use Pulsar\Extension\Cms\FieldRegistry\FieldType;

#[CoversClass(ContentTypeDefinition::class)]
#[CoversClass(ContentTypeField::class)]
final class FieldRegistryValueObjectsTest extends TestCase
{
    // -- ContentTypeDefinition ------------------------------------------------

    #[Test]
    public function contentTypeDefinitionConstructor(): void
    {
        $field = new ContentTypeField(
            id: 'f-01',
            contentType: 'blog_post',
            fieldKey: 'author_bio',
            fieldType: FieldType::String,
            required: false,
            translatable: true,
            searchable: true,
            filterable: false,
            sortable: false,
            validationRules: ['max_length' => 500],
            defaultValue: '',
            sortOrder: 0,
        );

        $definition = new ContentTypeDefinition(
            type: 'blog_post',
            label: 'Blog Post',
            icon: 'edit',
            fields: [$field],
        );

        self::assertSame('blog_post', $definition->type);
        self::assertSame('Blog Post', $definition->label);
        self::assertSame('edit', $definition->icon);
        self::assertCount(1, $definition->fields);
        self::assertSame('author_bio', $definition->fields[0]->fieldKey);
    }

    #[Test]
    public function contentTypeDefinitionWithNoFields(): void
    {
        $definition = new ContentTypeDefinition(
            type: 'simple_page',
            label: 'Simple Page',
            icon: 'file',
            fields: [],
        );

        self::assertSame([], $definition->fields);
    }

    // -- ContentTypeField -----------------------------------------------------

    #[Test]
    public function contentTypeFieldFullConstructor(): void
    {
        $field = new ContentTypeField(
            id: 'f-02',
            contentType: 'product',
            fieldKey: 'price',
            fieldType: FieldType::Int,
            required: true,
            translatable: false,
            searchable: false,
            filterable: true,
            sortable: true,
            validationRules: ['min' => 0],
            defaultValue: 0,
            sortOrder: 1,
        );

        self::assertSame('f-02', $field->id);
        self::assertSame('product', $field->contentType);
        self::assertSame('price', $field->fieldKey);
        self::assertSame(FieldType::Int, $field->fieldType);
        self::assertTrue($field->required);
        self::assertFalse($field->translatable);
        self::assertTrue($field->filterable);
        self::assertTrue($field->sortable);
        self::assertSame(['min' => 0], $field->validationRules);
        self::assertSame(0, $field->defaultValue);
    }

    #[Test]
    public function contentTypeFieldBooleanType(): void
    {
        $field = new ContentTypeField(
            id: 'f-03',
            contentType: 'article',
            fieldKey: 'featured',
            fieldType: FieldType::Bool,
            required: false,
            translatable: false,
            searchable: false,
            filterable: true,
            sortable: false,
            validationRules: [],
            defaultValue: false,
            sortOrder: 5,
        );

        self::assertSame(FieldType::Bool, $field->fieldType);
        self::assertFalse($field->defaultValue);
    }
}
