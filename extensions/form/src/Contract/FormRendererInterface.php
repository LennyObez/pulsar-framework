<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Contract;

use Pulsar\Api\Api;
use Pulsar\Http\Validation\ValidationResult;

/**
 * Contract for form HTML rendering.
 *
 * Renders forms and fields to accessible HTML markup with
 * proper ARIA attributes, labels, and error display.
 * @api
 */
#[Api(since: '1.0.0')]
interface FormRendererInterface
{
    /**
     * Render the complete form (open tag, fields, close tag).
     */
    public function renderForm(FormInterface $form): string;

    /**
     * Render the form opening tag with method, action, and enctype.
     */
    public function renderFormStart(FormInterface $form): string;

    /**
     * Render the form closing tag.
     */
    public function renderFormEnd(): string;

    /**
     * Render a single field with its label and error messages.
     */
    public function renderField(FieldInterface $field, ?ValidationResult $result = null): string;

    /**
     * Render the error summary block with links to fields.
     */
    public function renderErrorSummary(FormInterface $form): string;

    /**
     * Render the CSRF hidden field.
     */
    public function renderCsrfField(FormInterface $form): string;
}
