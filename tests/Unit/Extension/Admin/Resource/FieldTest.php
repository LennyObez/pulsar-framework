<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Admin\Resource;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Domain\FieldType;
use Pulsar\Extension\Admin\Resource\BooleanField;
use Pulsar\Extension\Admin\Resource\DateField;
use Pulsar\Extension\Admin\Resource\DateTimeField;
use Pulsar\Extension\Admin\Resource\EmailField;
use Pulsar\Extension\Admin\Resource\Field;
use Pulsar\Extension\Admin\Resource\JsonField;
use Pulsar\Extension\Admin\Resource\NumberField;
use Pulsar\Extension\Admin\Resource\RelationField;
use Pulsar\Extension\Admin\Resource\SelectField;
use Pulsar\Extension\Admin\Resource\TextareaField;
use Pulsar\Extension\Admin\Resource\TextField;
use Pulsar\Extension\Admin\Resource\UrlField;

#[CoversClass(Field::class)]
#[CoversClass(TextField::class)]
#[CoversClass(TextareaField::class)]
#[CoversClass(EmailField::class)]
#[CoversClass(SelectField::class)]
#[CoversClass(DateField::class)]
#[CoversClass(DateTimeField::class)]
#[CoversClass(NumberField::class)]
#[CoversClass(BooleanField::class)]
#[CoversClass(UrlField::class)]
#[CoversClass(JsonField::class)]
#[CoversClass(RelationField::class)]
final class FieldTest extends TestCase
{
    #[Test]
    public function textFieldMake(): void
    {
        $field = TextField::make('name');
        $def = $field->toFieldDefinition();

        self::assertSame('name', $def->name);
        self::assertSame(FieldType::String, $def->type);
        self::assertSame('Name', $def->label);
    }

    #[Test]
    public function textareaFieldMake(): void
    {
        $field = TextareaField::make('description');
        $def = $field->toFieldDefinition();

        self::assertSame(FieldType::Text, $def->type);
    }

    #[Test]
    public function emailFieldMakeAddsValidation(): void
    {
        $field = EmailField::make('email');
        $def = $field->toFieldDefinition();

        self::assertSame(FieldType::Email, $def->type);
        self::assertNotEmpty($def->rules);
        self::assertSame('email', $def->rules[0]->rule);
    }

    #[Test]
    public function emailFieldUnique(): void
    {
        $field = EmailField::make('email')->unique();
        $def = $field->toFieldDefinition();

        self::assertSame('Must be unique', $def->helpText);
    }

    #[Test]
    public function selectFieldWithStringOptions(): void
    {
        $field = SelectField::make('status')->options(['active', 'inactive']);
        $def = $field->toFieldDefinition();

        self::assertSame(FieldType::Enum, $def->type);
        self::assertSame(['active', 'inactive'], $def->enumValues);
    }

    #[Test]
    public function selectFieldWithEnumOptions(): void
    {
        $field = SelectField::make('status')->options(TestStatus::cases());
        $def = $field->toFieldDefinition();

        self::assertSame(['active', 'inactive', 'pending'], $def->enumValues);
    }

    #[Test]
    public function dateFieldMake(): void
    {
        $field = DateField::make('birth_date');
        $def = $field->toFieldDefinition();

        self::assertSame(FieldType::Date, $def->type);
        self::assertSame('Birth date', $def->label);
    }

    #[Test]
    public function dateTimeFieldMake(): void
    {
        $field = DateTimeField::make('published_at');
        $def = $field->toFieldDefinition();

        self::assertSame(FieldType::DateTime, $def->type);
    }

    #[Test]
    public function numberFieldInteger(): void
    {
        $field = NumberField::make('age');
        $def = $field->toFieldDefinition();

        self::assertSame(FieldType::Integer, $def->type);
    }

    #[Test]
    public function numberFieldFloat(): void
    {
        $field = NumberField::make('price', float: true);
        $def = $field->toFieldDefinition();

        self::assertSame(FieldType::Float, $def->type);
    }

    #[Test]
    public function booleanFieldMake(): void
    {
        $field = BooleanField::make('is_active');
        $def = $field->toFieldDefinition();

        self::assertSame(FieldType::Boolean, $def->type);
    }

    #[Test]
    public function urlFieldMakeAddsValidation(): void
    {
        $field = UrlField::make('website');
        $def = $field->toFieldDefinition();

        self::assertSame(FieldType::Url, $def->type);
        self::assertNotEmpty($def->rules);
        self::assertSame('url', $def->rules[0]->rule);
    }

    #[Test]
    public function jsonFieldMake(): void
    {
        $field = JsonField::make('metadata');
        $def = $field->toFieldDefinition();

        self::assertSame(FieldType::Json, $def->type);
    }

    #[Test]
    public function relationFieldWithResource(): void
    {
        $field = RelationField::make('author_id')->resource('users');
        $def = $field->toFieldDefinition();

        self::assertSame(FieldType::Relation, $def->type);
        self::assertSame('users', $def->relationResource);
    }

    #[Test]
    public function fluentModifiers(): void
    {
        $field = TextField::make('name')
            ->label('Full Name')
            ->sortable()
            ->filterable()
            ->searchable()
            ->required()
            ->minLength(2)
            ->maxLength(100)
            ->placeholder('Enter name')
            ->helpText('Your legal name');

        $def = $field->toFieldDefinition();

        self::assertSame('Full Name', $def->label);
        self::assertTrue($def->sortable);
        self::assertTrue($def->filterable);
        self::assertTrue($def->searchable);
        self::assertSame('Enter name', $def->placeholder);
        self::assertSame('Your legal name', $def->helpText);
        self::assertCount(3, $def->rules);
    }

    #[Test]
    public function readonlyField(): void
    {
        $field = TextField::make('id')->readonly();
        $def = $field->toFieldDefinition();

        self::assertFalse($def->editable);
    }

    #[Test]
    public function hiddenOnViews(): void
    {
        $field = TextField::make('internal')
            ->hiddenOnList()
            ->hiddenOnForm();

        $def = $field->toFieldDefinition();

        self::assertFalse($def->visibleOnList);
        self::assertFalse($def->visibleOnForm);
        self::assertTrue($def->visibleOnDetail);
    }

    #[Test]
    public function redactedField(): void
    {
        $field = TextField::make('ssn')->redacted();
        $def = $field->toFieldDefinition();

        self::assertTrue($def->redacted);
    }

    #[Test]
    public function notExportable(): void
    {
        $field = TextField::make('password')->exportable(false);

        self::assertFalse($field->isExportable);
    }

    #[Test]
    public function patternValidation(): void
    {
        $field = TextField::make('code')->pattern('/^[A-Z]{3}$/');
        $def = $field->toFieldDefinition();

        self::assertSame('pattern', $def->rules[0]->rule);
        self::assertSame('/^[A-Z]{3}$/', $def->rules[0]->parameter);
    }

    #[Test]
    public function minMaxValidation(): void
    {
        $field = NumberField::make('quantity')->min(1)->max(1000);
        $def = $field->toFieldDefinition();

        self::assertCount(2, $def->rules);
        self::assertSame('min', $def->rules[0]->rule);
        self::assertSame('max', $def->rules[1]->rule);
    }

    #[Test]
    public function customValidationMessage(): void
    {
        $field = TextField::make('email')->required('Email cannot be empty');
        $def = $field->toFieldDefinition();

        self::assertSame('Email cannot be empty', $def->rules[0]->message);
    }
}

/** @internal */
enum TestStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Pending = 'pending';
}
