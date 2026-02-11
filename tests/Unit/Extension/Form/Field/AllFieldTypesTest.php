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
final class AllFieldTypesTest extends TestCase
{
    #[Test]
    public function colorFieldHasColorType(): void
    {
        $field = new ColorField('accent');
        self::assertSame('color', $field->getType());
        self::assertSame('accent', $field->getName());
        self::assertSame('field-accent', $field->getId());
        self::assertSame('field-accent-error', $field->getErrorId());
    }

    #[Test]
    public function dateFieldWithMinMax(): void
    {
        $field = new DateField('birth', 'Date of birth', '1900-01-01', '2024-12-31');
        self::assertSame('date', $field->getType());
        self::assertSame('1900-01-01', $field->getMin());
        self::assertSame('2024-12-31', $field->getMax());
        self::assertSame('Date of birth', $field->getLabel());
    }

    #[Test]
    public function dateFieldWithNullBounds(): void
    {
        $field = new DateField('date');
        self::assertNull($field->getMin());
        self::assertNull($field->getMax());
    }

    #[Test]
    public function dateTimeFieldWithMinMax(): void
    {
        $field = new DateTimeField('appointment', 'Appointment', '2024-01-01T00:00', '2024-12-31T23:59');
        self::assertSame('datetime-local', $field->getType());
        self::assertSame('2024-01-01T00:00', $field->getMin());
        self::assertSame('2024-12-31T23:59', $field->getMax());
    }

    #[Test]
    public function emailFieldWithPlaceholder(): void
    {
        $field = new EmailField('email', 'Email', 'you@example.com');
        self::assertSame('email', $field->getType());
        self::assertSame('you@example.com', $field->getPlaceholder());
    }

    #[Test]
    public function emailFieldWithoutPlaceholder(): void
    {
        $field = new EmailField('email');
        self::assertNull($field->getPlaceholder());
    }

    #[Test]
    public function hiddenFieldHasHiddenType(): void
    {
        $field = new HiddenField('csrf_token');
        self::assertSame('hidden', $field->getType());
        $field->setValue('tok123');
        self::assertSame('tok123', $field->getValue());
    }

    #[Test]
    public function multiSelectFieldOptions(): void
    {
        $field = new MultiSelectField('colors', 'Colors', ['r' => 'Red', 'g' => 'Green', 'b' => 'Blue']);
        self::assertSame('select', $field->getType());
        self::assertSame(['r' => 'Red', 'g' => 'Green', 'b' => 'Blue'], $field->getOptions());
    }

    #[Test]
    public function radioFieldOptions(): void
    {
        $field = new RadioField('gender', 'Gender', ['m' => 'Male', 'f' => 'Female']);
        self::assertSame('radio', $field->getType());
        self::assertSame(['m' => 'Male', 'f' => 'Female'], $field->getOptions());
    }

    #[Test]
    public function rangeFieldMinMaxStep(): void
    {
        $field = new RangeField('volume', 'Volume', 0, 100, 5);
        self::assertSame('range', $field->getType());
        self::assertSame(0, $field->getMin());
        self::assertSame(100, $field->getMax());
        self::assertSame(5, $field->getStep());
    }

    #[Test]
    public function telFieldWithPlaceholderAndPattern(): void
    {
        $field = new TelField('phone', 'Phone', '+1-555-0100', '[0-9+\\-]{7,15}');
        self::assertSame('tel', $field->getType());
        self::assertSame('+1-555-0100', $field->getPlaceholder());
        self::assertSame('[0-9+\\-]{7,15}', $field->getPattern());
    }

    #[Test]
    public function textareaFieldAttributes(): void
    {
        $field = new TextareaField('bio', 'Bio', 3, 50, 500, 'Tell us about yourself');
        self::assertSame('textarea', $field->getType());
        self::assertSame(3, $field->getRows());
        self::assertSame(50, $field->getCols());
        self::assertSame(500, $field->getMaxLength());
        self::assertSame('Tell us about yourself', $field->getPlaceholder());
    }

    #[Test]
    public function textareaFieldNullAttributes(): void
    {
        $field = new TextareaField('notes');
        self::assertNull($field->getRows());
        self::assertNull($field->getCols());
        self::assertNull($field->getMaxLength());
        self::assertNull($field->getPlaceholder());
    }

    #[Test]
    public function timeFieldWithMinMaxStep(): void
    {
        $field = new TimeField('alarm', 'Alarm', '08:00', '22:00', '900');
        self::assertSame('time', $field->getType());
        self::assertSame('08:00', $field->getMin());
        self::assertSame('22:00', $field->getMax());
        self::assertSame('900', $field->getStep());
    }

    #[Test]
    public function timeFieldNullValues(): void
    {
        $field = new TimeField('time');
        self::assertNull($field->getMin());
        self::assertNull($field->getMax());
        self::assertNull($field->getStep());
    }

    #[Test]
    public function urlFieldWithPlaceholder(): void
    {
        $field = new UrlField('website', 'Website', 'https://example.com');
        self::assertSame('url', $field->getType());
        self::assertSame('https://example.com', $field->getPlaceholder());
    }

    #[Test]
    public function fieldLabelFallsBackToName(): void
    {
        $field = new ColorField('color');
        self::assertSame('color', $field->getLabel());
    }

    #[Test]
    public function fieldSetValueAndGetValue(): void
    {
        $field = new ColorField('bg');
        self::assertNull($field->getValue());
        $field->setValue('#ff0000');
        self::assertSame('#ff0000', $field->getValue());
    }

    #[Test]
    public function fieldRequiredAndDisabled(): void
    {
        $field = new EmailField('email');
        self::assertFalse($field->isRequired());
        self::assertFalse($field->isDisabled());

        $field->setRequired(true);
        $field->setDisabled(true);

        self::assertTrue($field->isRequired());
        self::assertTrue($field->isDisabled());
    }

    #[Test]
    public function fieldAttributes(): void
    {
        $field = new ColorField('bg');
        $field->setAttribute('data-testid', 'bg-color');
        self::assertSame('bg-color', $field->getAttributes()['data-testid']);

        $field->setAttributes(['aria-label' => 'Background']);
        self::assertSame(['aria-label' => 'Background'], $field->getAttributes());
    }

    #[Test]
    public function fieldRules(): void
    {
        $field = new ColorField('bg');
        self::assertSame([], $field->getRules());

        $rule = $this->createStub(\Pulsar\Http\Validation\RuleInterface::class);
        $field->setRules([$rule]);
        self::assertCount(1, $field->getRules());
    }
}
