<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Domain\FieldDefinition;
use Pulsar\Extension\Admin\Domain\FieldType;
use Pulsar\Extension\Admin\Domain\ValidationRule;

#[CoversClass(FieldDefinition::class)]
final class FieldDefinitionTest extends TestCase
{
    #[Test]
    public function constructWithRequiredProperties(): void
    {
        $field = new FieldDefinition(
            name: 'email',
            type: FieldType::Email,
            label: 'Email Address',
        );

        self::assertSame('email', $field->name);
        self::assertSame(FieldType::Email, $field->type);
        self::assertSame('Email Address', $field->label);
    }

    #[Test]
    public function defaultValues(): void
    {
        $field = new FieldDefinition(
            name: 'name',
            type: FieldType::String,
            label: 'Name',
        );

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
    public function constructWithAllProperties(): void
    {
        $rule = new ValidationRule(rule: 'required');
        $field = new FieldDefinition(
            name: 'status',
            type: FieldType::Enum,
            label: 'Status',
            sortable: true,
            filterable: true,
            searchable: true,
            redacted: false,
            exportable: false,
            editable: false,
            visibleOnList: true,
            visibleOnDetail: true,
            visibleOnForm: false,
            rules: [$rule],
            enumValues: ['active', 'inactive'],
            relationResource: null,
            placeholder: 'Select status',
            helpText: 'Choose the current status',
        );

        self::assertTrue($field->sortable);
        self::assertTrue($field->filterable);
        self::assertTrue($field->searchable);
        self::assertFalse($field->exportable);
        self::assertFalse($field->editable);
        self::assertFalse($field->visibleOnForm);
        self::assertCount(1, $field->rules);
        self::assertSame(['active', 'inactive'], $field->enumValues);
        self::assertSame('Select status', $field->placeholder);
        self::assertSame('Choose the current status', $field->helpText);
    }

    #[Test]
    public function redactedField(): void
    {
        $field = new FieldDefinition(
            name: 'ssn',
            type: FieldType::String,
            label: 'SSN',
            redacted: true,
            visibleOnList: false,
            exportable: false,
        );

        self::assertTrue($field->redacted);
        self::assertFalse($field->visibleOnList);
        self::assertFalse($field->exportable);
    }

    #[Test]
    public function relationField(): void
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
