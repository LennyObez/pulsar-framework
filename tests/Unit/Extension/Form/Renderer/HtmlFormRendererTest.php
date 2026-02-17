<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Form\Renderer;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Form\Builder\FormBuilder;
use Pulsar\Extension\Form\Config\FormConfig;
use Pulsar\Extension\Form\Config\RendererConfig;
use Pulsar\Extension\Form\Field\CheckboxField;
use Pulsar\Extension\Form\Field\RadioField;
use Pulsar\Extension\Form\Field\SelectField;
use Pulsar\Extension\Form\Field\TextareaField;
use Pulsar\Extension\Form\Field\TextField;
use Pulsar\Extension\Form\Renderer\HtmlFormRenderer;
use Pulsar\Http\Validation\Rule\Required;
use Pulsar\Http\Validation\Validator;

#[CoversClass(HtmlFormRenderer::class)]
final class HtmlFormRendererTest extends TestCase
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
    public function it_renders_form_start_with_attributes(): void
    {
        $form = $this->builder->id('login')->action('/login')->method('POST')->build();

        $html = $this->renderer->renderFormStart($form);

        self::assertStringContainsString('id="login"', $html);
        self::assertStringContainsString('action="/login"', $html);
        self::assertStringContainsString('method="POST"', $html);
        self::assertStringContainsString('novalidate', $html);
    }

    #[Test]
    public function it_renders_form_end(): void
    {
        self::assertSame('</form>', $this->renderer->renderFormEnd());
    }

    #[Test]
    public function it_renders_text_field_with_label(): void
    {
        $field = new TextField('username', 'Username');

        $html = $this->renderer->renderField($field);

        self::assertStringContainsString('<label for="field-username"', $html);
        self::assertStringContainsString('Username</label>', $html);
        self::assertStringContainsString('type="text"', $html);
        self::assertStringContainsString('name="username"', $html);
        self::assertStringContainsString('id="field-username"', $html);
    }

    #[Test]
    public function it_adds_aria_required_for_required_fields(): void
    {
        $field = new TextField('name', 'Name');
        $field->setRequired(true);

        $html = $this->renderer->renderField($field);

        self::assertStringContainsString('aria-required="true"', $html);
        self::assertStringContainsString('required="required"', $html);
    }

    #[Test]
    public function it_renders_errors_with_aria_attributes(): void
    {
        $field = new TextField('name', 'Name');
        $field->setRules([new Required()]);

        $form = $this->builder->id('test')->add($field)->build();
        $form->submit(['name' => '']);
        $result = $form->validate();

        $html = $this->renderer->renderField($field, $result);

        self::assertStringContainsString('aria-invalid="true"', $html);
        self::assertStringContainsString('aria-describedby="field-name-error"', $html);
        self::assertStringContainsString('id="field-name-error"', $html);
        self::assertStringContainsString('role="alert"', $html);
    }

    #[Test]
    public function it_renders_error_summary_with_links(): void
    {
        $field = new TextField('name', 'Name');
        $field->setRules([new Required()]);

        $form = $this->builder->id('test')->add($field)->build();
        $form->submit(['name' => '']);
        $form->validate();

        $html = $this->renderer->renderErrorSummary($form);

        self::assertStringContainsString('role="alert"', $html);
        self::assertStringContainsString('tabindex="-1"', $html);
        self::assertStringContainsString('<a href="#field-name">', $html);
        self::assertStringContainsString('There are errors in the form', $html);
    }

    #[Test]
    public function it_renders_select_field_with_options(): void
    {
        $field = new SelectField('country', 'Country', ['us' => 'United States', 'uk' => 'UK'], placeholder: 'Select...');

        $html = $this->renderer->renderField($field);

        self::assertStringContainsString('<select', $html);
        self::assertStringContainsString('<option value="">Select...</option>', $html);
        self::assertStringContainsString('<option value="us">United States</option>', $html);
    }

    #[Test]
    public function it_renders_textarea_field(): void
    {
        $field = new TextareaField('bio', 'Bio', rows: 5, cols: 40);

        $html = $this->renderer->renderField($field);

        self::assertStringContainsString('<textarea', $html);
        self::assertStringContainsString('rows="5"', $html);
        self::assertStringContainsString('cols="40"', $html);
    }

    #[Test]
    public function it_renders_radio_group_as_fieldset(): void
    {
        $field = new RadioField('color', 'Color', ['r' => 'Red', 'b' => 'Blue']);

        $html = $this->renderer->renderField($field);

        self::assertStringContainsString('<fieldset', $html);
        self::assertStringContainsString('role="radiogroup"', $html);
        self::assertStringContainsString('<legend', $html);
        self::assertStringContainsString('type="radio"', $html);
    }

    #[Test]
    public function it_renders_checkbox_field(): void
    {
        $field = new CheckboxField('agree', 'I Agree');

        $html = $this->renderer->renderField($field);

        self::assertStringContainsString('type="checkbox"', $html);
        self::assertStringContainsString('I Agree</label>', $html);
    }

    #[Test]
    public function it_escapes_html_in_labels(): void
    {
        $field = new TextField('name', '<script>alert("xss")</script>');

        $html = $this->renderer->renderField($field);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }
}
