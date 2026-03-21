<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Field;

use Override;
use Pulsar\Api\Api;

/**
 * Time input field (HH:MM).
 * @api
 */
#[Api(since: '1.0.0')]
final class TimeField extends AbstractField
{
    public function __construct(
        string $name,
        string $label = '',
        private readonly ?string $min = null,
        private readonly ?string $max = null,
        private readonly ?string $step = null,
    ) {
        parent::__construct($name, $label);
    }

    #[Override]
    public function getType(): string
    {
        return 'time';
    }

    public function getMin(): ?string
    {
        return $this->min;
    }

    public function getMax(): ?string
    {
        return $this->max;
    }

    public function getStep(): ?string
    {
        return $this->step;
    }
}
