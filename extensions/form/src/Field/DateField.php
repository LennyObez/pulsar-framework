<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Field;

use Override;
use Pulsar\Api\Api;

/**
 * Date input field (YYYY-MM-DD).
 */
#[Api(since: '1.0.0')]
final class DateField extends AbstractField
{
    public function __construct(
        string $name,
        string $label = '',
        private readonly ?string $min = null,
        private readonly ?string $max = null,
    ) {
        parent::__construct($name, $label);
    }

    #[Override]
    public function getType(): string
    {
        return 'date';
    }

    public function getMin(): ?string
    {
        return $this->min;
    }

    public function getMax(): ?string
    {
        return $this->max;
    }
}
