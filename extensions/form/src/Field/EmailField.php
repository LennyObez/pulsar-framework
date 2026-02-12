<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Field;

use Override;
use Pulsar\Api\Api;

/**
 * Email input field with built-in format validation.
 */
#[Api(since: '1.0.0')]
final class EmailField extends AbstractField
{
    public function __construct(
        string $name,
        string $label = '',
        private readonly ?string $placeholder = null,
    ) {
        parent::__construct($name, $label);
    }

    #[Override]
    public function getType(): string
    {
        return 'email';
    }

    public function getPlaceholder(): ?string
    {
        return $this->placeholder;
    }
}
