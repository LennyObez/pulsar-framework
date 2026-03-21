<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Field;

use Override;
use Pulsar\Api\Api;

/**
 * Telephone number input field.
 * @api
 */
#[Api(since: '1.0.0')]
final class TelField extends AbstractField
{
    public function __construct(
        string $name,
        string $label = '',
        private readonly ?string $placeholder = null,
        private readonly ?string $pattern = null,
    ) {
        parent::__construct($name, $label);
    }

    #[Override]
    public function getType(): string
    {
        return 'tel';
    }

    public function getPlaceholder(): ?string
    {
        return $this->placeholder;
    }

    public function getPattern(): ?string
    {
        return $this->pattern;
    }
}
