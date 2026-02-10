<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Field;

use Override;
use Pulsar\Api\Api;

/**
 * Single-line text input field.
 */
#[Api(since: '1.0.0')]
final class TextField extends AbstractField
{
    public function __construct(
        string $name,
        string $label = '',
        private readonly ?int $minLength = null,
        private readonly ?int $maxLength = null,
        private readonly ?string $placeholder = null,
        private readonly ?string $pattern = null,
    ) {
        parent::__construct($name, $label);
    }

    #[Override]
    public function getType(): string
    {
        return 'text';
    }

    public function getMinLength(): ?int
    {
        return $this->minLength;
    }

    public function getMaxLength(): ?int
    {
        return $this->maxLength;
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
