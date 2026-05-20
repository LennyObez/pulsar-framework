<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Renderer;

use Override;
use Pulsar\Api\Api;
use Pulsar\Extension\Form\Builder\Form;
use Pulsar\Extension\Form\Config\RendererConfig;
use Pulsar\Extension\Form\Contract\FieldInterface;
use Pulsar\Extension\Form\Contract\FormInterface;
use Pulsar\Extension\Form\Contract\FormRendererInterface;
use Pulsar\Extension\Form\Field\CheckboxField;
use Pulsar\Extension\Form\Field\DateField;
use Pulsar\Extension\Form\Field\DateTimeField;
use Pulsar\Extension\Form\Field\FileField;
use Pulsar\Extension\Form\Field\MultiSelectField;
use Pulsar\Extension\Form\Field\NumberField;
use Pulsar\Extension\Form\Field\PasswordField;
use Pulsar\Extension\Form\Field\RadioField;
use Pulsar\Extension\Form\Field\RangeField;
use Pulsar\Extension\Form\Field\Regulated\AbstractRegulatedField;
use Pulsar\Extension\Form\Field\SelectField;
use Pulsar\Extension\Form\Field\TelField;
use Pulsar\Extension\Form\Field\TextareaField;
use Pulsar\Extension\Form\Field\TextField;
use Pulsar\Extension\Form\Field\TimeField;
use Pulsar\Http\Validation\ValidationResult;
use Pulsar\Http\Validation\Violation;

use function htmlspecialchars;
use function implode;
use function in_array;
use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function sprintf;

use const ENT_QUOTES;

/**
 * Renders forms to accessible HTML markup.
 *
 * Produces WCAG 2.1 AA compliant HTML with proper ARIA attributes,
 * labels, error display, and focus management for error summaries.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class HtmlFormRenderer implements FormRendererInterface
{
    public function __construct(
        private RendererConfig $config,
    ) {}

    #[Override]
    public function renderForm(FormInterface $form): string
    {
        $parts = [];
        $parts[] = $this->renderFormStart($form);

        if ($form->isSubmitted() && $form->getValidationResult()->failed()) {
            $parts[] = $this->renderErrorSummary($form);
        }

        if ($form->isCsrfEnabled()) {
            $parts[] = $this->renderCsrfField($form);
        }

        $result = $form->isSubmitted() ? $form->getValidationResult() : null;

        foreach ($form->getFields() as $field) {
            $parts[] = $this->renderField($field, $result);
        }

        $parts[] = $this->renderFormEnd();

        return implode("\n", $parts);
    }

    #[Override]
    public function renderFormStart(FormInterface $form): string
    {
        $attrs = [
            'id' => $this->esc($form->getId()),
            'method' => $this->esc($form->getMethod()),
            'action' => $this->esc($form->getAction()),
            'novalidate' => 'novalidate',
        ];

        if ($this->formHasFileField($form)) {
            $attrs['enctype'] = 'multipart/form-data';
        }

        return '<form ' . $this->buildAttributes($attrs) . '>';
    }

    #[Override]
    public function renderFormEnd(): string
    {
        return '</form>';
    }

    #[Override]
    public function renderField(FieldInterface $field, ?ValidationResult $result = null): string
    {
        $errors = $result !== null ? $result->forField($field->getName()) : [];
        $hasErrors = $errors !== [];

        return match (true) {
            $field instanceof TextareaField => $this->renderTextarea($field, $hasErrors, $errors),
            $field instanceof SelectField => $this->renderSelect($field, $hasErrors, $errors),
            $field instanceof MultiSelectField => $this->renderMultiSelect($field, $hasErrors, $errors),
            $field instanceof RadioField => $this->renderRadioGroup($field, $hasErrors, $errors),
            $field instanceof CheckboxField => $this->renderCheckbox($field, $hasErrors, $errors),
            $field instanceof AbstractRegulatedField => $this->renderRegulatedField($field, $hasErrors, $errors),
            default => $this->renderInput($field, $hasErrors, $errors),
        };
    }

    #[Override]
    public function renderErrorSummary(FormInterface $form): string
    {
        $result = $form->getValidationResult();

        if ($result->passed()) {
            return '';
        }

        $items = [];

        foreach ($result->violations as $violation) {
            $fieldId = 'field-' . $violation->field;
            $items[] = sprintf(
                '<li><a href="#%s">%s</a></li>',
                $this->esc($fieldId),
                $this->esc($violation->message),
            );
        }

        return sprintf(
            '<div class="%s" role="alert" aria-labelledby="%s-heading" tabindex="-1" id="%s-error-summary">' .
            '<h2 id="%s-heading">There are errors in the form</h2>' .
            '<ul>%s</ul>' .
            '</div>',
            $this->esc($this->config->errorSummaryClass),
            $this->esc($form->getId()),
            $this->esc($form->getId()),
            $this->esc($form->getId()),
            implode('', $items),
        );
    }

    #[Override]
    public function renderCsrfField(FormInterface $form): string
    {
        if (! $form instanceof Form) {
            return '';
        }

        $token = $form->getCsrfToken();

        if ($token === null) {
            return '';
        }

        return sprintf(
            '<input type="hidden" name="%s" value="%s">',
            $this->esc($form->getCsrfFieldName()),
            $this->esc($token),
        );
    }

    /**
     * @param list<Violation> $errors
     */
    private function renderInput(FieldInterface $field, bool $hasErrors, array $errors): string
    {
        $attrs = $this->buildFieldAttributes($field, $hasErrors);
        $value = $field->getValue();

        if (is_string($value) || is_int($value) || is_float($value)) {
            $attrs['value'] = $this->esc((string) $value);
        }

        // Add type-specific attributes
        if ($field instanceof TextField) {
            $this->addTextAttributes($attrs, $field);
        } elseif ($field instanceof PasswordField) {
            $this->addPasswordAttributes($attrs, $field);
        } elseif ($field instanceof NumberField) {
            $this->addNumberAttributes($attrs, $field);
        } elseif ($field instanceof DateField || $field instanceof DateTimeField) {
            $this->addDateAttributes($attrs, $field);
        } elseif ($field instanceof TimeField) {
            $this->addTimeAttributes($attrs, $field);
        } elseif ($field instanceof RangeField) {
            $this->addRangeAttributes($attrs, $field);
        } elseif ($field instanceof TelField) {
            $this->addTelAttributes($attrs, $field);
        } elseif ($field instanceof FileField) {
            $this->addFileAttributes($attrs, $field);
            unset($attrs['value']); // File inputs cannot have a value
        }

        $html = $this->renderLabel($field);
        $html .= '<input ' . $this->buildAttributes($attrs) . '>';
        $html .= $this->renderErrors($field, $errors);

        return '<div class="form-group">' . $html . '</div>';
    }

    /**
     * @param list<Violation> $errors
     */
    private function renderTextarea(TextareaField $field, bool $hasErrors, array $errors): string
    {
        $attrs = $this->buildFieldAttributes($field, $hasErrors);
        unset($attrs['type']);

        if ($field->getRows() !== null) {
            $attrs['rows'] = (string) $field->getRows();
        }

        if ($field->getCols() !== null) {
            $attrs['cols'] = (string) $field->getCols();
        }

        if ($field->getMaxLength() !== null) {
            $attrs['maxlength'] = (string) $field->getMaxLength();
        }

        if ($field->getPlaceholder() !== null) {
            $attrs['placeholder'] = $this->esc($field->getPlaceholder());
        }

        /** @var mixed $rawValue */
        $rawValue = $field->getValue();
        $value = $this->esc(is_string($rawValue) ? $rawValue : '');

        $html = $this->renderLabel($field);
        $html .= '<textarea ' . $this->buildAttributes($attrs) . '>' . $value . '</textarea>';
        $html .= $this->renderErrors($field, $errors);

        return '<div class="form-group">' . $html . '</div>';
    }

    /**
     * @param list<Violation> $errors
     */
    private function renderSelect(SelectField $field, bool $hasErrors, array $errors): string
    {
        $attrs = $this->buildFieldAttributes($field, $hasErrors);
        unset($attrs['type']);

        $options = '';

        if ($field->getPlaceholder() !== null) {
            $options .= sprintf('<option value="">%s</option>', $this->esc($field->getPlaceholder()));
        }

        /** @var mixed $fieldValue */
        $fieldValue = $field->getValue();
        $fieldValueStr = is_string($fieldValue) || is_int($fieldValue) ? (string) $fieldValue : '';

        foreach ($field->getOptions() as $optValue => $optLabel) {
            $selected = $fieldValueStr === $optValue ? ' selected' : '';
            $options .= sprintf(
                '<option value="%s"%s>%s</option>',
                $this->esc($optValue),
                $selected,
                $this->esc($optLabel),
            );
        }

        $html = $this->renderLabel($field);
        $html .= '<select ' . $this->buildAttributes($attrs) . '>' . $options . '</select>';
        $html .= $this->renderErrors($field, $errors);

        return '<div class="form-group">' . $html . '</div>';
    }

    /**
     * @param list<Violation> $errors
     */
    private function renderMultiSelect(MultiSelectField $field, bool $hasErrors, array $errors): string
    {
        $attrs = $this->buildFieldAttributes($field, $hasErrors);
        unset($attrs['type']);
        $attrs['multiple'] = 'multiple';
        $attrs['name'] = $this->esc($field->getName()) . '[]';

        /** @var list<string> $selectedValues */
        $selectedValues = is_array($field->getValue()) ? $field->getValue() : [];
        $options = '';

        foreach ($field->getOptions() as $optValue => $optLabel) {
            $selected = in_array($optValue, $selectedValues, true) ? ' selected' : '';
            $options .= sprintf(
                '<option value="%s"%s>%s</option>',
                $this->esc($optValue),
                $selected,
                $this->esc($optLabel),
            );
        }

        $html = $this->renderLabel($field);
        $html .= '<select ' . $this->buildAttributes($attrs) . '>' . $options . '</select>';
        $html .= $this->renderErrors($field, $errors);

        return '<div class="form-group">' . $html . '</div>';
    }

    /**
     * @param list<Violation> $errors
     */
    private function renderRadioGroup(RadioField $field, bool $hasErrors, array $errors): string
    {
        $html = sprintf(
            '<fieldset role="radiogroup" aria-labelledby="%s-legend">',
            $this->esc($field->getId()),
        );
        $html .= sprintf(
            '<legend id="%s-legend" class="%s">%s</legend>',
            $this->esc($field->getId()),
            $this->esc($this->config->labelClass),
            $this->esc($field->getLabel()),
        );

        if ($hasErrors) {
            $html .= sprintf(
                '<div id="%s" class="%s" role="alert">',
                $this->esc($field->getErrorId()),
                $this->esc($this->config->errorClass),
            );

            foreach ($errors as $error) {
                $html .= sprintf('<p>%s</p>', $this->esc($error->message));
            }

            $html .= '</div>';
        }

        /** @var mixed $radioValue */
        $radioValue = $field->getValue();
        $radioValueStr = is_string($radioValue) || is_int($radioValue) ? (string) $radioValue : '';

        foreach ($field->getOptions() as $optValue => $optLabel) {
            $optId = $field->getId() . '-' . $optValue;
            $checked = $radioValueStr === $optValue ? ' checked' : '';
            $html .= sprintf(
                '<div class="form-radio"><input type="radio" id="%s" name="%s" value="%s"%s%s>' .
                '<label for="%s">%s</label></div>',
                $this->esc($optId),
                $this->esc($field->getName()),
                $this->esc($optValue),
                $checked,
                $hasErrors ? sprintf(' aria-describedby="%s"', $this->esc($field->getErrorId())) : '',
                $this->esc($optId),
                $this->esc($optLabel),
            );
        }

        $html .= '</fieldset>';

        return '<div class="form-group">' . $html . '</div>';
    }

    /**
     * @param list<Violation> $errors
     */
    private function renderCheckbox(CheckboxField $field, bool $hasErrors, array $errors): string
    {
        $attrs = $this->buildFieldAttributes($field, $hasErrors);
        $attrs['value'] = $this->esc($field->getCheckedValue());

        if ($field->isChecked()) {
            $attrs['checked'] = 'checked';
        }

        $html = '<input ' . $this->buildAttributes($attrs) . '>';
        $html .= sprintf(
            '<label for="%s" class="%s">%s</label>',
            $this->esc($field->getId()),
            $this->esc($this->config->labelClass),
            $this->esc($field->getLabel()),
        );
        $html .= $this->renderErrors($field, $errors);

        return '<div class="form-group form-check">' . $html . '</div>';
    }

    /**
     * @param list<Violation> $errors
     */
    private function renderRegulatedField(AbstractRegulatedField $field, bool $hasErrors, array $errors): string
    {
        $attrs = $this->buildFieldAttributes($field, $hasErrors);

        if ($field->getType() === 'checkbox') {
            $attrs['value'] = '1';

            if ($field->getValue()) {
                $attrs['checked'] = 'checked';
            }
        }

        $html = '<input ' . $this->buildAttributes($attrs) . '>';
        $html .= sprintf(
            '<label for="%s" class="%s">%s</label>',
            $this->esc($field->getId()),
            $this->esc($this->config->labelClass),
            $this->esc($field->getLabel()),
        );

        // Display policy text
        $html .= sprintf(
            '<div class="form-policy-text" id="%s-policy">%s</div>',
            $this->esc($field->getId()),
            $this->esc($field->policyText),
        );

        $html .= $this->renderErrors($field, $errors);

        return '<div class="form-group form-regulated">' . $html . '</div>';
    }

    private function renderLabel(FieldInterface $field): string
    {
        if ($field->getType() === 'hidden') {
            return '';
        }

        return sprintf(
            '<label for="%s" class="%s">%s</label>',
            $this->esc($field->getId()),
            $this->esc($this->config->labelClass),
            $this->esc($field->getLabel()),
        );
    }

    /**
     * @param list<Violation> $errors
     */
    private function renderErrors(FieldInterface $field, array $errors): string
    {
        if ($errors === []) {
            return '';
        }

        $html = sprintf(
            '<div id="%s" class="%s" role="alert">',
            $this->esc($field->getErrorId()),
            $this->esc($this->config->errorClass),
        );

        foreach ($errors as $error) {
            $html .= sprintf('<p>%s</p>', $this->esc($error->message));
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Build common HTML attributes for a field.
     *
     * @return array<string, string>
     */
    private function buildFieldAttributes(FieldInterface $field, bool $hasErrors): array
    {
        $attrs = [
            'type' => $this->esc($field->getType()),
            'id' => $this->esc($field->getId()),
            'name' => $this->esc($field->getName()),
            'class' => $this->esc($this->config->inputClass),
        ];

        if ($field->isRequired()) {
            $attrs['required'] = 'required';
            $attrs['aria-required'] = 'true';
        }

        if ($field->isDisabled()) {
            $attrs['disabled'] = 'disabled';
        }

        if ($hasErrors) {
            $attrs['aria-invalid'] = 'true';
            $attrs['aria-describedby'] = $this->esc($field->getErrorId());
        }

        // Merge custom attributes
        foreach ($field->getAttributes() as $key => $value) {
            if ($value === true) {
                $attrs[$key] = $key;
            } elseif ($value !== false) {
                $attrs[$key] = $this->esc($value);
            }
        }

        return $attrs;
    }

    /**
     * @param array<string, string> $attrs
     */
    private function addTextAttributes(array &$attrs, TextField $field): void
    {
        if ($field->getMinLength() !== null) {
            $attrs['minlength'] = (string) $field->getMinLength();
        }

        if ($field->getMaxLength() !== null) {
            $attrs['maxlength'] = (string) $field->getMaxLength();
        }

        if ($field->getPlaceholder() !== null) {
            $attrs['placeholder'] = $this->esc($field->getPlaceholder());
        }

        if ($field->getPattern() !== null) {
            $attrs['pattern'] = $this->esc($field->getPattern());
        }
    }

    /**
     * @param array<string, string> $attrs
     */
    private function addPasswordAttributes(array &$attrs, PasswordField $field): void
    {
        if ($field->getMinLength() !== null) {
            $attrs['minlength'] = (string) $field->getMinLength();
        }

        if ($field->getPlaceholder() !== null) {
            $attrs['placeholder'] = $this->esc($field->getPlaceholder());
        }
    }

    /**
     * @param array<string, string> $attrs
     */
    private function addNumberAttributes(array &$attrs, NumberField $field): void
    {
        if ($field->getMin() !== null) {
            $attrs['min'] = (string) $field->getMin();
        }

        if ($field->getMax() !== null) {
            $attrs['max'] = (string) $field->getMax();
        }

        if ($field->getStep() !== null) {
            $attrs['step'] = (string) $field->getStep();
        }

        if ($field->getPlaceholder() !== null) {
            $attrs['placeholder'] = $this->esc($field->getPlaceholder());
        }
    }

    /**
     * @param array<string, string> $attrs
     */
    private function addDateAttributes(array &$attrs, DateField|DateTimeField $field): void
    {
        if ($field->getMin() !== null) {
            $attrs['min'] = $this->esc($field->getMin());
        }

        if ($field->getMax() !== null) {
            $attrs['max'] = $this->esc($field->getMax());
        }
    }

    /**
     * @param array<string, string> $attrs
     */
    private function addTimeAttributes(array &$attrs, TimeField $field): void
    {
        if ($field->getMin() !== null) {
            $attrs['min'] = $this->esc($field->getMin());
        }

        if ($field->getMax() !== null) {
            $attrs['max'] = $this->esc($field->getMax());
        }

        if ($field->getStep() !== null) {
            $attrs['step'] = $this->esc($field->getStep());
        }
    }

    /**
     * @param array<string, string> $attrs
     */
    private function addRangeAttributes(array &$attrs, RangeField $field): void
    {
        $attrs['min'] = (string) $field->getMin();
        $attrs['max'] = (string) $field->getMax();
        $attrs['step'] = (string) $field->getStep();
    }

    /**
     * @param array<string, string> $attrs
     */
    private function addTelAttributes(array &$attrs, TelField $field): void
    {
        if ($field->getPlaceholder() !== null) {
            $attrs['placeholder'] = $this->esc($field->getPlaceholder());
        }

        if ($field->getPattern() !== null) {
            $attrs['pattern'] = $this->esc($field->getPattern());
        }
    }

    /**
     * @param array<string, string> $attrs
     */
    private function addFileAttributes(array &$attrs, FileField $field): void
    {
        if ($field->isMultiple()) {
            $attrs['multiple'] = 'multiple';
            $attrs['name'] = $this->esc($field->getName()) . '[]';
        }

        if ($field->getAccept() !== null) {
            $attrs['accept'] = $this->esc($field->getAccept());
        }
    }

    /**
     * @param array<string, string> $attrs
     */
    private function buildAttributes(array $attrs): string
    {
        $parts = [];

        foreach ($attrs as $key => $value) {
            $parts[] = sprintf('%s="%s"', $key, $value);
        }

        return implode(' ', $parts);
    }

    private function formHasFileField(FormInterface $form): bool
    {
        return array_any($form->getFields(), static fn(FieldInterface $field): bool => $field instanceof FileField);
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
