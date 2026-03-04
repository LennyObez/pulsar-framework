<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Domain\FieldDefinition;
use Pulsar\Extension\Admin\Domain\FieldType;
use Pulsar\Extension\Admin\Domain\ValidationRule;

final class FieldDefinitionTest extends TestCase
{
    #[Test]
    public function construction_with_defaults(): void
    {
        $field = new FieldDefinition(
            name: 'email',
            type: FieldType::Email,
            label: 'Email Address',
        );

        self::assertSame('email', $field->name);
        self::assertSame(FieldType::Email, $field->type);
        self::assertSame('Email Address', $field->label);
        self::assertFalse($field->sortable);
        self::assertFalse($field->filterable);
        self::assertFalse($field->searchable);
        self::assertFalse($field->redacted);
        self::assertTrue($field->exportable);
        self::assertTrue($field->editable);
        self::assertTrue($field->visibleOnList);
        self::assertTrue($field->visibleOnDetail);
        self::assertTrue($field->visibleOnForm);
        self::assertSame([], $field->rules);
        self::assertSame([], $field->enumValues);
        self::assertNull($field->relationResource);
        self::assertNull($field->placeholder);
        self::assertNull($field->helpText);
    }

    #[Test]
    public function construction_fully_customized(): void
    {
        $rule = new ValidationRule('required', 'This field is required');

        $field = new FieldDefinition(
            name: 'status',
            type: FieldType::Enum,
            label: 'Status',
            sortable: true,
            filterable: true,
            searchable: true,
            redacted: false,
            exportable: false,
            editable: true,
            visibleOnList: true,
            visibleOnDetail: true,
            visibleOnForm: true,
            rules: [$rule],
            enumValues: ['active', 'inactive', 'pending'],
            placeholder: 'Select a status',
            helpText: 'Choose the item status',
        );

        self::assertSame('status', $field->name);
        self::assertSame(FieldType::Enum, $field->type);
        self::assertTrue($field->sortable);
        self::assertTrue($field->filterable);
        self::assertTrue($field->searchable);
        self::assertFalse($field->exportable);
        self::assertCount(1, $field->rules);
        self::assertSame(['active', 'inactive', 'pending'], $field->enumValues);
        self::assertSame('Select a status', $field->placeholder);
        self::assertSame('Choose the item status', $field->helpText);
    }

    #[Test]
    public function redacted_field(): void
    {
        $field = new FieldDefinition(
            name: 'password',
            type: FieldType::String,
            label: 'Password',
            redacted: true,
            visibleOnList: false,
            exportable: false,
        );

        self::assertTrue($field->redacted);
        self::assertFalse($field->visibleOnList);
        self::assertFalse($field->exportable);
    }

    #[Test]
    public function relation_field(): void
    {
        $field = new FieldDefinition(
            name: 'author_id',
            type: FieldType::Relation,
            label: 'Author',
            relationResource: 'users',
        );

        self::assertSame(FieldType::Relation, $field->type);
        self::assertSame('users', $field->relationResource);
    }
}
