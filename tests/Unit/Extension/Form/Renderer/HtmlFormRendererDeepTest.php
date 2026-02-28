<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Form\Renderer;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Form\Builder\FormBuilder;
use Pulsar\Extension\Form\Config\FormConfig;
use Pulsar\Extension\Form\Config\RendererConfig;
use Pulsar\Extension\Form\Contract\FormInterface;
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
use Pulsar\Extension\Form\Field\Regulated\ConsentCheckbox;
use Pulsar\Extension\Form\Field\SelectField;
use Pulsar\Extension\Form\Field\TelField;
use Pulsar\Extension\Form\Field\TextareaField;
use Pulsar\Extension\Form\Field\TextField;
use Pulsar\Extension\Form\Field\TimeField;
use Pulsar\Extension\Form\Field\UrlField;
use Pulsar\Extension\Form\Renderer\HtmlFormRenderer;
use Pulsar\Http\Validation\Rule\Required;
use Pulsar\Http\Validation\Validator;

#[CoversClass(HtmlFormRenderer::class)]
final class HtmlFormRendererDeepTest extends TestCase
{
    private HtmlFormRenderer $renderer;
    private FormBuilder $builder;

    protected function setUp(): void
    {
        $rendererConfig = RendererConfig::fromArray([]);
        $this->renderer = new HtmlFormRenderer($rendererConfig);

        $formConfig = FormConfig::fromArray(['csrf' => ['enabled' => false]]);
        $this->builder = new FormBuilder($formConfig, new Validator());
    }

    #[Test]
    public function rendersFileFieldWithMultiple(): void
    {
        $field = new FileField('docs', 'Documents', multiple: true, accept: '.pdf,.doc');

        $html = $this->renderer->renderField($field);

        self::assertStringContainsString('type="file"', $html);
        self::assertStringContainsString('multiple="multiple"', $html);
        self::assertStringContainsString('accept=".pdf,.doc"', $html);
        self::assertStringNotContainsString('value=', $html);
    }

    #[Test]
    public function rendersFileFieldSingle(): void
    {
        $field = new FileField('avatar', 'Avatar');

        $html = $this->renderer->renderField($field);

        self::assertStringContainsString('type="file"', $html);
        self::assertStringNotContainsString('multiple', $html);
    }

    #[Test]
    public function rendersNumberFieldWithConstraints(): void
    {
        $field = new NumberField('age', 'Age', min: 0, max: 150, step: 1, placeholder: 'Enter age');

        $html = $this->renderer->renderField($field);

        self::assertStringContainsString('type="number"', $html);
        self::assertStringContainsString('min="0"', $html);
        self::assertStringContainsString('max="150"', $html);
        self::assertStringContainsString('step="1"', $html);
        self::assertStringContainsString('placeholder="Enter age"', $html);
    }

    #[Test]
    public function rendersPasswordFieldWithMinLength(): void
    {
        $field = new PasswordField('password', 'Password', minLength: 8, placeholder: 'Min 8 chars');

        $html = $this->renderer->renderField($field);

        self::assertStringContainsString('type="password"', $html);
        self::assertStringContainsString('minlength="8"', $html);
        self::assertStringContainsString('placeholder="Min 8 chars"', $html);
    }

    #[Test]
    public function rendersDateFieldWithMinMax(): void
    {
        $field = new DateField('birth', 'Date of Birth', '1900-01-01', '2024-12-31');

        $html = $this->renderer->renderField($field);

        self::assertStringContainsString('type="date"', $html);
        self::assertStringContainsString('min="1900-01-01"', $html);
        self::assertStringContainsString('max="2024-12-31"', $html);
    }

    #[Test]
    public function rendersDateTimeFieldWithMinMax(): void
    {
        $field = new DateTimeField('appointment', 'Appointment', '2024-01-01T00:00', '2024-12-31T23:59');

        $html = $this->renderer->renderField($field);

        self::assertStringContainsString('type="datetime-local"', $html);
        self::assertStringContainsString('min="2024-01-01T00:00"', $html);
        self::assertStringContainsString('max="2024-12-31T23:59"', $html);
    }

    #[Test]
    public function rendersTimeFieldWithMinMaxStep(): void
    {
        $field = new TimeField('alarm', 'Alarm', '08:00', '22:00', '900');

        $html = $this->renderer->renderField($field);

        self::assertStringContainsString('type="time"', $html);
        self::assertStringContainsString('min="08:00"', $html);
        self::assertStringContainsString('max="22:00"', $html);
        self::assertStringContainsString('step="900"', $html);
    }

    #[Test]
    public function rendersRangeFieldWithMinMaxStep(): void
    {
        $field = new RangeField('volume', 'Volume', 0, 100, 5);

        $html = $this->renderer->renderField($field);

        self::assertStringContainsString('type="range"', $html);
        self::assertStringContainsString('min="0"', $html);
        self::assertStringContainsString('max="100"', $html);
        self::assertStringContainsString('step="5"', $html);
    }

    #[Test]
    public function rendersTelFieldWithPlaceholderAndPattern(): void
    {
        $field = new TelField('phone', 'Phone', '+1-555-0100', '[0-9]{10}');

        $html = $this->renderer->renderField($field);

        self::assertStringContainsString('type="tel"', $html);
        self::assertStringContainsString('placeholder="+1-555-0100"', $html);
        self::assertStringContainsString('pattern="[0-9]{10}"', $html);
    }

    #[Test]
    public function rendersMultiSelectFieldWithSelectedValues(): void
    {
        $field = new MultiSelectField('colors', 'Colors', ['r' => 'Red', 'g' => 'Green', 'b' => 'Blue']);
        $field->setValue(['r', 'b']);

        $html = $this->renderer->renderField($field);

        self::assertStringContainsString('<select', $html);
        self::assertStringContainsString('multiple="multiple"', $html);
        self::assertStringContainsString('colors[]', $html);
        self::assertStringContainsString('<option value="r" selected>Red</option>', $html);
        self::assertStringContainsString('<option value="g">Green</option>', $html);
        self::assertStringContainsString('<option value="b" selected>Blue</option>', $html);
    }

    #[Test]
    public function rendersHiddenFieldWithoutLabel(): void
    {
        $field = new HiddenField('token');
        $field->setValue('abc123');

        $html = $this->renderer->renderField($field);

        self::assertStringContainsString('type="hidden"', $html);
        self::assertStringContainsString('value="abc123"', $html);
        self::assertStringNotContainsString('<label', $html);
    }

    #[Test]
    public function rendersDisabledField(): void
    {
        $field = new TextField('name', 'Name');
        $field->setDisabled(true);

        $html = $this->renderer->renderField($field);

        self::assertStringContainsString('disabled="disabled"', $html);
    }

    #[Test]
    public function rendersFieldWithCustomBooleanAttribute(): void
    {
        $field = new TextField('name', 'Name');
        $field->setAttribute('autofocus', true);

        $html = $this->renderer->renderField($field);

        self::assertStringContainsString('autofocus="autofocus"', $html);
    }

    #[Test]
    public function rendersFieldWithFalseAttributeOmitted(): void
    {
        $field = new TextField('name', 'Name');
        $field->setAttribute('hidden', false);

        $html = $this->renderer->renderField($field);

        self::assertStringNotContainsString('hidden=', $html);
    }

    #[Test]
    public function rendersCheckboxChecked(): void
    {
        $field = new CheckboxField('agree', 'I Agree', '1');
        $field->setValue('1');

        $html = $this->renderer->renderField($field);

        self::assertStringContainsString('checked="checked"', $html);
        self::assertStringContainsString('value="1"', $html);
    }

    #[Test]
    public function rendersRadioGroupWithErrors(): void
    {
        $field = new RadioField('color', 'Color', ['r' => 'Red', 'b' => 'Blue']);
        $field->setRules([new Required()]);
        $field->setValue('r');

        $form = $this->builder->id('test')->add($field)->build();
        $form->submit(['color' => '']);
        $result = $form->validate();

        $html = $this->renderer->renderField($field, $result);

        self::assertStringContainsString('role="alert"', $html);
        self::assertStringContainsString('aria-describedby=', $html);
    }

    #[Test]
    public function rendersRadioGroupWithSelectedValue(): void
    {
        $field = new RadioField('color', 'Color', ['r' => 'Red', 'b' => 'Blue']);
        $field->setValue('b');

        $html = $this->renderer->renderField($field);

        self::assertStringContainsString('checked', $html);
    }

    #[Test]
    public function rendersConsentCheckboxAsRegulatedField(): void
    {
        $field = new ConsentCheckbox(
            'gdpr',
            'GDPR Consent',
            'marketing',
            'v2.0',
            'I agree to receive marketing emails.',
        );
        $field->setValue(true);

        $html = $this->renderer->renderField($field);

        self::assertStringContainsString('form-regulated', $html);
        self::assertStringContainsString('checked="checked"', $html);
        self::assertStringContainsString('form-policy-text', $html);
        self::assertStringContainsString('I agree to receive marketing emails.', $html);
    }

    #[Test]
    public function rendersRegulatedFieldWithoutCheckboxType(): void
    {
        // Consent checkbox unchecked
        $field = new ConsentCheckbox(
            'terms',
            'Terms',
            'terms_of_service',
            'v1.0',
            'You must accept the terms.',
        );

        $html = $this->renderer->renderField($field);

        self::assertStringContainsString('type="checkbox"', $html);
        self::assertStringNotContainsString('checked', $html);
    }

    #[Test]
    public function rendersSelectFieldWithSelectedValue(): void
    {
        $field = new SelectField('country', 'Country', ['us' => 'USA', 'uk' => 'UK']);
        $field->setValue('uk');

        $html = $this->renderer->renderField($field);

        self::assertStringContainsString('<option value="uk" selected>UK</option>', $html);
        self::assertStringNotContainsString('selected>USA', $html);
    }

    #[Test]
    public function rendersTextareaWithValue(): void
    {
        $field = new TextareaField('bio', 'Bio', maxLength: 500, placeholder: 'Tell us...');
        $field->setValue('Hello world');

        $html = $this->renderer->renderField($field);

        self::assertStringContainsString('>Hello world</textarea>', $html);
        self::assertStringContainsString('maxlength="500"', $html);
        self::assertStringContainsString('placeholder="Tell us..."', $html);
    }

    #[Test]
    public function rendersTextFieldWithPatternAndMinMaxLength(): void
    {
        $field = new TextField('code', 'Code', minLength: 3, maxLength: 10, placeholder: 'ABC123', pattern: '[A-Z0-9]+');

        $html = $this->renderer->renderField($field);

        self::assertStringContainsString('minlength="3"', $html);
        self::assertStringContainsString('maxlength="10"', $html);
        self::assertStringContainsString('placeholder="ABC123"', $html);
        self::assertStringContainsString('pattern="[A-Z0-9]+"', $html);
    }

    #[Test]
    public function renderFormWithFileFieldAddsEnctype(): void
    {
        $form = $this->builder
            ->id('upload')
            ->action('/upload')
            ->method('POST')
            ->add(new FileField('doc', 'Document'))
            ->build();

        $html = $this->renderer->renderFormStart($form);

        self::assertStringContainsString('enctype="multipart/form-data"', $html);
    }

    #[Test]
    public function renderFormWithoutFileFieldNoEnctype(): void
    {
        $form = $this->builder
            ->id('login')
            ->action('/login')
            ->method('POST')
            ->add(new TextField('username', 'Username'))
            ->build();

        $html = $this->renderer->renderFormStart($form);

        self::assertStringNotContainsString('enctype', $html);
    }

    #[Test]
    public function renderFullFormWithErrors(): void
    {
        $nameField = new TextField('name', 'Name');
        $nameField->setRules([new Required()]);

        $form = $this->builder
            ->id('test')
            ->action('/submit')
            ->method('POST')
            ->add($nameField)
            ->build();

        $form->submit(['name' => '']);
        $form->validate();

        $html = $this->renderer->renderForm($form);

        self::assertStringContainsString('<form', $html);
        self::assertStringContainsString('form-error-summary', $html);
        self::assertStringContainsString('</form>', $html);
    }

    #[Test]
    public function renderFullFormWithoutErrors(): void
    {
        $form = $this->builder
            ->id('test')
            ->action('/submit')
            ->method('POST')
            ->add(new TextField('name', 'Name'))
            ->build();

        $html = $this->renderer->renderForm($form);

        self::assertStringContainsString('<form', $html);
        self::assertStringNotContainsString('form-error-summary', $html);
        self::assertStringContainsString('</form>', $html);
    }

    #[Test]
    public function errorSummaryReturnsEmptyForPassedValidation(): void
    {
        $form = $this->builder
            ->id('test')
            ->add(new TextField('name', 'Name'))
            ->build();

        $form->submit(['name' => 'John']);
        $form->validate();

        $html = $this->renderer->renderErrorSummary($form);

        self::assertSame('', $html);
    }

    #[Test]
    public function csrfFieldRendersEmptyForNonFormInstance(): void
    {
        $formStub = $this->createStub(FormInterface::class);
        $formStub->method('isCsrfEnabled')->willReturn(true);

        $html = $this->renderer->renderCsrfField($formStub);

        self::assertSame('', $html);
    }

    #[Test]
    public function rendersFieldWithNumericValue(): void
    {
        $field = new NumberField('quantity', 'Quantity');
        $field->setValue(42);

        $html = $this->renderer->renderField($field);

        self::assertStringContainsString('value="42"', $html);
    }

    #[Test]
    public function rendersFieldWithFloatValue(): void
    {
        $field = new NumberField('price', 'Price');
        $field->setValue(9.99);

        $html = $this->renderer->renderField($field);

        self::assertStringContainsString('value="9.99"', $html);
    }

    #[Test]
    public function rendersEmailField(): void
    {
        $field = new EmailField('email', 'Email', 'user@example.com');

        $html = $this->renderer->renderField($field);

        self::assertStringContainsString('type="email"', $html);
    }

    #[Test]
    public function rendersUrlField(): void
    {
        $field = new UrlField('website', 'Website', 'https://example.com');

        $html = $this->renderer->renderField($field);

        self::assertStringContainsString('type="url"', $html);
    }

    #[Test]
    public function rendersColorField(): void
    {
        $field = new ColorField('bg');
        $field->setValue('#ff0000');

        $html = $this->renderer->renderField($field);

        self::assertStringContainsString('type="color"', $html);
        self::assertStringContainsString('value="#ff0000"', $html);
    }
}
