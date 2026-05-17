<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Field;

use Override;
use Pulsar\Api\Api;
use Pulsar\Extension\Form\Contract\FieldInterface;
use Pulsar\Http\Validation\RuleInterface;

use function array_key_exists;
use function is_array;

/**
 * Fieldset grouping related fields together.
 *
 * Contains child fields and renders as an HTML <fieldset> with <legend>.
 * @api
 */
#[Api(since: '1.0.0')]
final class FieldsetField implements FieldInterface
{
    /** @var array<string, FieldInterface> */
    private array $children = [];

    private mixed $value = null;

    public function __construct(
        private readonly string $name,
        private readonly string $label = '',
        private readonly string $legend = '',
    ) {}

    #[Override]
    public function getName(): string
    {
        return $this->name;
    }

    #[Override]
    public function getLabel(): string
    {
        return $this->label !== '' ? $this->label : $this->name;
    }

    #[Override]
    public function getType(): string
    {
        return 'fieldset';
    }

    #[Override]
    public function getValue(): mixed
    {
        return $this->value;
    }

    #[Override]
    public function setValue(mixed $value): void
    {
        $this->value = $value;

        // Cascade values to children if array
        if (is_array($value)) {
            foreach ($this->children as $childName => $child) {
                if (array_key_exists($childName, $value)) {
                    $child->setValue($value[$childName]);
                }
            }
        }
    }

    #[Override]
    public function isRequired(): bool
    {
        return false;
    }

    #[Override]
    public function isDisabled(): bool
    {
        return false;
    }

    /**
     * @return array<string, string|bool>
     */
    #[Override]
    public function getAttributes(): array
    {
        return [];
    }

    /**
     * @return list<RuleInterface>
     */
    #[Override]
    public function getRules(): array
    {
        return [];
    }

    public function getLegend(): string
    {
        return $this->legend !== '' ? $this->legend : $this->label;
    }

    public function add(FieldInterface $field): self
    {
        $this->children[$field->getName()] = $field;

        return $this;
    }

    public function remove(string $name): self
    {
        unset($this->children[$name]);

        return $this;
    }

    public function getChild(string $name): ?FieldInterface
    {
        return $this->children[$name] ?? null;
    }

    /**
     * @return array<string, FieldInterface>
     */
    public function getChildren(): array
    {
        return $this->children;
    }

    public function getId(): string
    {
        return 'fieldset-' . $this->name;
    }

    public function getErrorId(): string
    {
        return $this->getId() . '-error';
    }
}
