<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Field;

use Override;
use Pulsar\Api\Api;

/**
 * Single-select dropdown field.
 */
#[Api(since: '1.0.0')]
final class SelectField extends AbstractField
{
    /**
     * @param array<string, string> $options Value => label pairs
     */
    public function __construct(
        string $name,
        string $label = '',
        private array $options = [],
        private readonly ?string $placeholder = null,
    ) {
        parent::__construct($name, $label);
    }

    #[Override]
    public function getType(): string
    {
        return 'select';
    }

    /**
     * @return array<string, string>
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * @param array<string, string> $options
     */
    public function setOptions(array $options): self
    {
        $this->options = $options;

        return $this;
    }

    public function getPlaceholder(): ?string
    {
        return $this->placeholder;
    }
}
