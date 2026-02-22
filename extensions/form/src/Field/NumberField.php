<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Field;

use Override;
use Pulsar\Api\Api;

/**
 * Numeric input field with optional min/max/step constraints.
 */
#[Api(since: '1.0.0')]
final class NumberField extends AbstractField
{
    public function __construct(
        string $name,
        string $label = '',
        private readonly int|float|null $min = null,
        private readonly int|float|null $max = null,
        private readonly int|float|null $step = null,
        private readonly ?string $placeholder = null,
    ) {
        parent::__construct($name, $label);
    }

    #[Override]
    public function getType(): string
    {
        return 'number';
    }

    public function getMin(): int|float|null
    {
        return $this->min;
    }

    public function getMax(): int|float|null
    {
        return $this->max;
    }

    public function getStep(): int|float|null
    {
        return $this->step;
    }

    public function getPlaceholder(): ?string
    {
        return $this->placeholder;
    }
}
