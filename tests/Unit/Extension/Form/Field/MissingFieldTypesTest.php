<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Form\Field;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Form\Field\ColorField;
use Pulsar\Extension\Form\Field\DateField;
use Pulsar\Extension\Form\Field\DateTimeField;
use Pulsar\Extension\Form\Field\EmailField;
use Pulsar\Extension\Form\Field\HiddenField;
use Pulsar\Extension\Form\Field\MultiSelectField;
use Pulsar\Extension\Form\Field\RadioField;
use Pulsar\Extension\Form\Field\RangeField;
use Pulsar\Extension\Form\Field\TelField;
use Pulsar\Extension\Form\Field\TextareaField;
use Pulsar\Extension\Form\Field\TimeField;
use Pulsar\Extension\Form\Field\UrlField;

#[CoversClass(ColorField::class)]
#[CoversClass(DateField::class)]
#[CoversClass(DateTimeField::class)]
#[CoversClass(EmailField::class)]
#[CoversClass(HiddenField::class)]
#[CoversClass(MultiSelectField::class)]
#[CoversClass(RadioField::class)]
#[CoversClass(RangeField::class)]
#[CoversClass(TelField::class)]
#[CoversClass(TextareaField::class)]
#[CoversClass(TimeField::class)]
#[CoversClass(UrlField::class)]
final class MissingFieldTypesTest extends TestCase
{
    // --- ColorField ---

    #[Test]
    public function colorFieldReturnsColorType(): void
    {
        $field = new ColorField('brand_color', 'Brand Color');
        self::assertSame('color', $field->getType());
        self::assertSame('brand_color', $field->getName());
        self::assertSame('Brand Color', $field->getLabel());
    }

    #[Test]
    public function colorFieldAcceptsHexValue(): void
    {
        $field = new ColorField('accent', 'Accent');
        $field->setValue('#ff6600');
        self::assertSame('#ff6600', $field->getValue());
    }

    // --- DateField ---

    #[Test]
    public function dateFieldReturnsDateType(): void
    {
        $field = new DateField('dob', 'Date of Birth');
        self::assertSame('date', $field->getType());
        self::assertSame('dob', $field->getName());
    }

    #[Test]
    public function dateFieldMinMax(): void
    {
        $field = new DateField('appointment', 'Appointment', min: '2025-01-01', max: '2025-12-31');
        self::assertSame('2025-01-01', $field->getMin());
        self::assertSame('2025-12-31', $field->getMax());
    }

    #[Test]
    public function dateFieldNullMinMax(): void
    {
        $field = new DateField('date');
        self::assertNull($field->getMin());
        self::assertNull($field->getMax());
    }

    // --- DateTimeField ---

    #[Test]
    public function dateTimeFieldReturnsDatetimeLocalType(): void
    {
        $field = new DateTimeField('event_start', 'Event Start');
        self::assertSame('datetime-local', $field->getType());
    }

    #[Test]
    public function dateTimeFieldMinMax(): void
    {
        $field = new DateTimeField('event', 'Event', min: '2025-06-01T09:00', max: '2025-06-30T17:00');
        self::assertSame('2025-06-01T09:00', $field->getMin());
        self::assertSame('2025-06-30T17:00', $field->getMax());
    }

    #[Test]
    public function dateTimeFieldNullMinMax(): void
    {
        $field = new DateTimeField('start');
        self::assertNull($field->getMin());
        self::assertNull($field->getMax());
    }

    // --- EmailField ---

    #[Test]
    public function emailFieldReturnsEmailType(): void
    {
        $field = new EmailField('email', 'Email Address');
        self::assertSame('email', $field->getType());
    }

    #[Test]
    public function emailFieldPlaceholder(): void
    {
        $field = new EmailField('contact_email', 'Email', placeholder: 'name@example.com');
        self::assertSame('name@example.com', $field->getPlaceholder());
    }

    #[Test]
    public function emailFieldNullPlaceholder(): void
    {
        $field = new EmailField('email');
        self::assertNull($field->getPlaceholder());
    }

    // --- HiddenField ---

    #[Test]
    public function hiddenFieldReturnsHiddenType(): void
    {
        $field = new HiddenField('_token', 'Token');
        self::assertSame('hidden', $field->getType());
    }

    #[Test]
    public function hiddenFieldStoresValue(): void
    {
        $field = new HiddenField('csrf_token');
        $field->setValue('abc123-def456-ghi789');
        self::assertSame('abc123-def456-ghi789', $field->getValue());
    }

    // --- MultiSelectField ---

    #[Test]
    public function multiSelectFieldReturnsSelectType(): void
    {
        $field = new MultiSelectField('tags', 'Tags');
        self::assertSame('select', $field->getType());
    }

    #[Test]
    public function multiSelectFieldIsMultiple(): void
    {
        $field = new MultiSelectField('roles', 'Roles');
        self::assertTrue($field->isMultiple());
    }

    #[Test]
    public function multiSelectFieldGetAndSetOptions(): void
    {
        $options = ['admin' => 'Administrator', 'editor' => 'Editor', 'viewer' => 'Viewer'];
        $field = new MultiSelectField('roles', 'Roles', $options);
        self::assertSame($options, $field->getOptions());

        $newOptions = ['manager' => 'Manager'];
        $field->setOptions($newOptions);
        self::assertSame($newOptions, $field->getOptions());
    }

    #[Test]
    public function multiSelectFieldAcceptsArrayValue(): void
    {
        $field = new MultiSelectField('categories', 'Categories', ['a' => 'A', 'b' => 'B']);
        $field->setValue(['a', 'b']);
        self::assertSame(['a', 'b'], $field->getValue());
    }

    // --- RadioField ---

    #[Test]
    public function radioFieldReturnsRadioType(): void
    {
        $field = new RadioField('gender', 'Gender');
        self::assertSame('radio', $field->getType());
    }

    #[Test]
    public function radioFieldGetAndSetOptions(): void
    {
        $options = ['male' => 'Male', 'female' => 'Female', 'other' => 'Other'];
        $field = new RadioField('gender', 'Gender', $options);
        self::assertSame($options, $field->getOptions());

        $field->setOptions(['yes' => 'Yes', 'no' => 'No']);
        self::assertSame(['yes' => 'Yes', 'no' => 'No'], $field->getOptions());
    }

    // --- RangeField ---

    #[Test]
    public function rangeFieldReturnsRangeType(): void
    {
        $field = new RangeField('volume', 'Volume');
        self::assertSame('range', $field->getType());
    }

    #[Test]
    public function rangeFieldDefaults(): void
    {
        $field = new RangeField('slider');
        self::assertSame(0, $field->getMin());
        self::assertSame(100, $field->getMax());
        self::assertSame(1, $field->getStep());
    }

    #[Test]
    public function rangeFieldCustomBounds(): void
    {
        $field = new RangeField('temperature', 'Temperature', min: -20.0, max: 50.0, step: 0.5);
        self::assertSame(-20.0, $field->getMin());
        self::assertSame(50.0, $field->getMax());
        self::assertSame(0.5, $field->getStep());
    }

    // --- TelField ---

    #[Test]
    public function telFieldReturnsTelType(): void
    {
        $field = new TelField('phone', 'Phone Number');
        self::assertSame('tel', $field->getType());
    }

    #[Test]
    public function telFieldPlaceholderAndPattern(): void
    {
        $field = new TelField('phone', 'Phone', placeholder: '+1 (555) 000-0000', pattern: '\\+?[0-9\\-\\s]+');
        self::assertSame('+1 (555) 000-0000', $field->getPlaceholder());
        self::assertSame('\\+?[0-9\\-\\s]+', $field->getPattern());
    }

    #[Test]
    public function telFieldNullPlaceholderAndPattern(): void
    {
        $field = new TelField('fax');
        self::assertNull($field->getPlaceholder());
        self::assertNull($field->getPattern());
    }

    // --- TextareaField ---

    #[Test]
    public function textareaFieldReturnsTextareaType(): void
    {
        $field = new TextareaField('description', 'Description');
        self::assertSame('textarea', $field->getType());
    }

    #[Test]
    public function textareaFieldDimensions(): void
    {
        $field = new TextareaField('bio', 'Biography', rows: 10, cols: 80, maxLength: 5000);
        self::assertSame(10, $field->getRows());
        self::assertSame(80, $field->getCols());
        self::assertSame(5000, $field->getMaxLength());
    }

    #[Test]
    public function textareaFieldPlaceholder(): void
    {
        $field = new TextareaField('notes', 'Notes', placeholder: 'Enter your notes here...');
        self::assertSame('Enter your notes here...', $field->getPlaceholder());
    }

    #[Test]
    public function textareaFieldNullProperties(): void
    {
        $field = new TextareaField('text');
        self::assertNull($field->getRows());
        self::assertNull($field->getCols());
        self::assertNull($field->getMaxLength());
        self::assertNull($field->getPlaceholder());
    }

    // --- TimeField ---

    #[Test]
    public function timeFieldReturnsTimeType(): void
    {
        $field = new TimeField('start_time', 'Start Time');
        self::assertSame('time', $field->getType());
    }

    #[Test]
    public function timeFieldMinMaxStep(): void
    {
        $field = new TimeField('meeting', 'Meeting Time', min: '09:00', max: '17:00', step: '900');
        self::assertSame('09:00', $field->getMin());
        self::assertSame('17:00', $field->getMax());
        self::assertSame('900', $field->getStep());
    }

    #[Test]
    public function timeFieldNullProperties(): void
    {
        $field = new TimeField('time');
        self::assertNull($field->getMin());
        self::assertNull($field->getMax());
        self::assertNull($field->getStep());
    }

    // --- UrlField ---

    #[Test]
    public function urlFieldReturnsUrlType(): void
    {
        $field = new UrlField('website', 'Website');
        self::assertSame('url', $field->getType());
    }

    #[Test]
    public function urlFieldPlaceholder(): void
    {
        $field = new UrlField('homepage', 'Homepage', placeholder: 'https://example.com');
        self::assertSame('https://example.com', $field->getPlaceholder());
    }

    #[Test]
    public function urlFieldNullPlaceholder(): void
    {
        $field = new UrlField('link');
        self::assertNull($field->getPlaceholder());
    }
}
