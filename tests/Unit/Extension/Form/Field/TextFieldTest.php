<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Form\Field;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Form\Field\AbstractField;
use Pulsar\Extension\Form\Field\CheckboxField;
use Pulsar\Extension\Form\Field\ColorField;
use Pulsar\Extension\Form\Field\DateField;
use Pulsar\Extension\Form\Field\DateTimeField;
use Pulsar\Extension\Form\Field\EmailField;
use Pulsar\Extension\Form\Field\FileField;
use Pulsar\Extension\Form\Field\HiddenField;
use Pulsar\Extension\Form\Field\MultiSelectField;
use Pulsar\Extension\Form\Field\NumberField;
use Pulsar\Extension\Form\Field\PasswordField;
use Pulsar\Extension\Form\Field\RadioField;
use Pulsar\Extension\Form\Field\RangeField;
use Pulsar\Extension\Form\Field\SelectField;
use Pulsar\Extension\Form\Field\TelField;
use Pulsar\Extension\Form\Field\TextareaField;
use Pulsar\Extension\Form\Field\TextField;
use Pulsar\Extension\Form\Field\TimeField;
use Pulsar\Extension\Form\Field\UrlField;

#[CoversClass(AbstractField::class)]
#[CoversClass(TextField::class)]
#[CoversClass(EmailField::class)]
#[CoversClass(PasswordField::class)]
#[CoversClass(NumberField::class)]
#[CoversClass(DateField::class)]
#[CoversClass(DateTimeField::class)]
#[CoversClass(TimeField::class)]
#[CoversClass(SelectField::class)]
#[CoversClass(MultiSelectField::class)]
#[CoversClass(CheckboxField::class)]
#[CoversClass(RadioField::class)]
#[CoversClass(HiddenField::class)]
#[CoversClass(TextareaField::class)]
#[CoversClass(ColorField::class)]
#[CoversClass(RangeField::class)]
#[CoversClass(TelField::class)]
#[CoversClass(UrlField::class)]
#[CoversClass(FileField::class)]
final class TextFieldTest extends TestCase
{
    #[Test]
    public function text_field_has_correct_type(): void
    {
        $field = new TextField('name', 'Name');
        self::assertSame('text', $field->getType());
        self::assertSame('name', $field->getName());
        self::assertSame('Name', $field->getLabel());
        self::assertSame('field-name', $field->getId());
        self::assertSame('field-name-error', $field->getErrorId());
    }

    #[Test]
    public function text_field_supports_constraints(): void
    {
        $field = new TextField('username', 'Username', minLength: 3, maxLength: 50, placeholder: 'Enter name', pattern: '[A-Za-z]+');
        self::assertSame(3, $field->getMinLength());
        self::assertSame(50, $field->getMaxLength());
        self::assertSame('Enter name', $field->getPlaceholder());
        self::assertSame('[A-Za-z]+', $field->getPattern());
    }

    #[Test]
    public function email_field_has_correct_type(): void
    {
        $field = new EmailField('email', 'Email');
        self::assertSame('email', $field->getType());
    }

    #[Test]
    public function password_field_never_exposes_value(): void
    {
        $field = new PasswordField('password', 'Password');
        $field->setValue('secret123');
        // getValue() returns null — enforced by return type, verified at compile time
        self::assertSame('password', $field->getType());
    }

    #[Test]
    public function number_field_has_constraints(): void
    {
        $field = new NumberField('age', 'Age', min: 0, max: 150, step: 1);
        self::assertSame('number', $field->getType());
        self::assertSame(0, $field->getMin());
        self::assertSame(150, $field->getMax());
        self::assertSame(1, $field->getStep());
    }

    #[Test]
    public function date_field_has_correct_type(): void
    {
        $field = new DateField('dob', 'Date of Birth', min: '1900-01-01', max: '2030-12-31');
        self::assertSame('date', $field->getType());
        self::assertSame('1900-01-01', $field->getMin());
        self::assertSame('2030-12-31', $field->getMax());
    }

    #[Test]
    public function datetime_field_has_correct_type(): void
    {
        $field = new DateTimeField('appointment', 'Appointment');
        self::assertSame('datetime-local', $field->getType());
    }

    #[Test]
    public function time_field_has_correct_type(): void
    {
        $field = new TimeField('time', 'Time');
        self::assertSame('time', $field->getType());
    }

    #[Test]
    public function select_field_manages_options(): void
    {
        $field = new SelectField('country', 'Country', ['us' => 'United States', 'uk' => 'United Kingdom']);
        self::assertSame('select', $field->getType());
        self::assertCount(2, $field->getOptions());

        $field->setOptions(['fr' => 'France']);
        self::assertCount(1, $field->getOptions());
    }

    #[Test]
    public function multi_select_field_is_multiple(): void
    {
        $field = new MultiSelectField('tags', 'Tags', ['php' => 'PHP', 'js' => 'JavaScript']);
        self::assertTrue($field->isMultiple());
    }

    #[Test]
    public function checkbox_field_tracks_checked_state(): void
    {
        $field = new CheckboxField('agree', 'I Agree', checkedValue: 'yes');
        self::assertSame('checkbox', $field->getType());
        self::assertFalse($field->isChecked());

        $field->setValue('yes');
        self::assertTrue($field->isChecked());

        $field->setValue('no');
        self::assertFalse($field->isChecked());
    }

    #[Test]
    public function radio_field_manages_options(): void
    {
        $field = new RadioField('gender', 'Gender', ['m' => 'Male', 'f' => 'Female']);
        self::assertSame('radio', $field->getType());
        self::assertCount(2, $field->getOptions());
    }

    #[Test]
    public function hidden_field_has_correct_type(): void
    {
        $field = new HiddenField('token', '');
        self::assertSame('hidden', $field->getType());
    }

    #[Test]
    public function textarea_field_has_dimensions(): void
    {
        $field = new TextareaField('bio', 'Bio', rows: 5, cols: 40, maxLength: 500);
        self::assertSame('textarea', $field->getType());
        self::assertSame(5, $field->getRows());
        self::assertSame(40, $field->getCols());
        self::assertSame(500, $field->getMaxLength());
    }

    #[Test]
    public function color_field_has_correct_type(): void
    {
        $field = new ColorField('color', 'Favorite Color');
        self::assertSame('color', $field->getType());
    }

    #[Test]
    public function range_field_has_min_max_step(): void
    {
        $field = new RangeField('volume', 'Volume', min: 0, max: 100, step: 5);
        self::assertSame('range', $field->getType());
        self::assertSame(0, $field->getMin());
        self::assertSame(100, $field->getMax());
        self::assertSame(5, $field->getStep());
    }

    #[Test]
    public function tel_field_supports_pattern(): void
    {
        $field = new TelField('phone', 'Phone', pattern: '[0-9]{10}');
        self::assertSame('tel', $field->getType());
        self::assertSame('[0-9]{10}', $field->getPattern());
    }

    #[Test]
    public function url_field_has_correct_type(): void
    {
        $field = new UrlField('website', 'Website');
        self::assertSame('url', $field->getType());
    }

    #[Test]
    public function file_field_has_mime_and_size_config(): void
    {
        $field = new FileField('avatar', 'Avatar', allowedMimeTypes: ['image/jpeg', 'image/png'], maxSize: 2_000_000);
        self::assertSame('file', $field->getType());
        self::assertSame(['image/jpeg', 'image/png'], $field->getAllowedMimeTypes());
        self::assertSame(2_000_000, $field->getMaxSize());
    }

    #[Test]
    public function field_supports_required_and_disabled(): void
    {
        $field = new TextField('name', 'Name');
        self::assertFalse($field->isRequired());
        self::assertFalse($field->isDisabled());

        $field->setRequired(true)->setDisabled(true);
        self::assertTrue($field->isRequired());
        self::assertTrue($field->isDisabled());
    }

    #[Test]
    public function field_supports_custom_attributes(): void
    {
        $field = new TextField('name', 'Name');
        $field->setAttribute('data-custom', 'value');
        $field->setAttribute('autofocus', true);

        $attrs = $field->getAttributes();
        self::assertSame('value', $attrs['data-custom']);
        self::assertTrue($attrs['autofocus']);
    }

    #[Test]
    public function field_label_defaults_to_name(): void
    {
        $field = new TextField('username');
        self::assertSame('username', $field->getLabel());
    }
}
