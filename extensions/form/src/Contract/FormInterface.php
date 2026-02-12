<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Contract;

use Pulsar\Api\Api;
use Pulsar\Http\Validation\ValidationResult;

/**
 * Contract for a form instance.
 *
 * A form holds fields, manages submission data, runs validation,
 * and tracks its submission state.
 */
#[Api(since: '1.0.0')]
interface FormInterface
{
    /**
     * Get the unique form identifier.
     */
    public function getId(): string;

    /**
     * Get the HTTP method for this form.
     */
    public function getMethod(): string;

    /**
     * Get the form action URL.
     */
    public function getAction(): string;

    /**
     * Get all fields in this form.
     *
     * @return array<string, FieldInterface>
     */
    public function getFields(): array;

    /**
     * Get a specific field by name.
     */
    public function getField(string $name): FieldInterface;

    /**
     * Check if a field exists.
     */
    public function hasField(string $name): bool;

    /**
     * Submit the form with request data.
     *
     * @param array<string, mixed> $data
     */
    public function submit(array $data): void;

    /**
     * Whether the form has been submitted.
     */
    public function isSubmitted(): bool;

    /**
     * Validate the form and return the result.
     */
    public function validate(): ValidationResult;

    /**
     * Whether the form passed validation (must be submitted first).
     */
    public function isValid(): bool;

    /**
     * Get the validation result (must be submitted first).
     */
    public function getValidationResult(): ValidationResult;

    /**
     * Get submitted and validated data.
     *
     * @return array<string, mixed>
     */
    public function getData(): array;

    /**
     * Whether CSRF protection is enabled.
     */
    public function isCsrfEnabled(): bool;
}
