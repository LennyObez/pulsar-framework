<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Field;

use Pulsar\Api\Api;
use Pulsar\Extension\Form\Contract\FieldInterface;
use Pulsar\Http\Validation\RuleInterface;

/**
 * Base implementation for form fields.
 *
 * Provides common field functionality: name, label, value,
 * validation rules, and HTML attributes.
 */
#[Api(since: '1.0.0')]
abstract class AbstractField implements FieldInterface
{
    protected mixed $value = null;

    /** @var list<RuleInterface> */
    protected array $rules = [];

    /** @var array<string, string|bool> */
    protected array $attributes = [];

    protected bool $required = false;
    protected bool $disabled = false;

    public function __construct(
        protected readonly string $name,
        protected readonly string $label = '',
    ) {}

    public function getName(): string
    {
        return $this->name;
    }

    public function getLabel(): string
    {
        return $this->label !== '' ? $this->label : $this->name;
    }

    abstract public function getType(): string;

    public function getValue(): mixed
    {
        return $this->value;
    }

    public function setValue(mixed $value): void
    {
        $this->value = $value;
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function isDisabled(): bool
    {
        return $this->disabled;
    }

    /**
     * @return array<string, string|bool>
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * @return list<RuleInterface>
     */
    public function getRules(): array
    {
        return $this->rules;
    }

    /**
     * @param list<RuleInterface> $rules
     */
    public function setRules(array $rules): static
    {
        $this->rules = $rules;

        return $this;
    }

    public function setRequired(bool $required): static
    {
        $this->required = $required;

        return $this;
    }

    public function setDisabled(bool $disabled): static
    {
        $this->disabled = $disabled;

        return $this;
    }

    public function setAttribute(string $key, string|bool $value): static
    {
        $this->attributes[$key] = $value;

        return $this;
    }

    /**
     * @param array<string, string|bool> $attributes
     */
    public function setAttributes(array $attributes): static
    {
        $this->attributes = $attributes;

        return $this;
    }

    /**
     * Get the HTML element ID for this field.
     */
    public function getId(): string
    {
        return 'field-' . $this->name;
    }

    /**
     * Get the error container ID for accessibility linkage.
     */
    public function getErrorId(): string
    {
        return $this->getId() . '-error';
    }
}
