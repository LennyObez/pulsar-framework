<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Field;

use Override;
use Pulsar\Api\Api;

/**
 * Range slider input field.
 * @api
 */
#[Api(since: '1.0.0')]
final class RangeField extends AbstractField
{
    public function __construct(
        string $name,
        string $label = '',
        private readonly int|float $min = 0,
        private readonly int|float $max = 100,
        private readonly int|float $step = 1,
    ) {
        parent::__construct($name, $label);
    }

    #[Override]
    public function getType(): string
    {
        return 'range';
    }

    public function getMin(): int|float
    {
        return $this->min;
    }

    public function getMax(): int|float
    {
        return $this->max;
    }

    public function getStep(): int|float
    {
        return $this->step;
    }
}
