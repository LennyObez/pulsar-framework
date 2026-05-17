<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Contract;

use Pulsar\Api\Api;
use Pulsar\Http\Validation\RuleInterface;

/**
 * Contract for form fields.
 *
 * Each field knows its name, label, type, HTML attributes,
 * and the validation rules that apply to it.
 * @api
 */
#[Api(since: '1.0.0')]
interface FieldInterface
{
    /**
     * Machine-readable field name (used as HTML name attribute).
     */
    public function getName(): string;

    /**
     * Human-readable label for the field.
     */
    public function getLabel(): string;

    /**
     * HTML input type (e.g., "text", "email", "select").
     */
    public function getType(): string;

    /**
     * Current field value.
     */
    public function getValue(): mixed;

    /**
     * Set the field value.
     */
    public function setValue(mixed $value): void;

    /**
     * Whether the field is required.
     */
    public function isRequired(): bool;

    /**
     * Whether the field is disabled.
     */
    public function isDisabled(): bool;

    /**
     * Get extra HTML attributes for rendering.
     *
     * @return array<string, string|bool>
     */
    public function getAttributes(): array;

    /**
     * Get validation rules for this field.
     *
     * @return list<RuleInterface>
     */
    public function getRules(): array;

    /**
     * Get the HTML element ID for this field.
     */
    public function getId(): string;

    /**
     * Get the error container ID for accessibility linkage.
     */
    public function getErrorId(): string;
}
