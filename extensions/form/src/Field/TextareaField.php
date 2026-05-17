<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Field;

use Override;
use Pulsar\Api\Api;

/**
 * Multi-line text input field.
 * @api
 */
#[Api(since: '1.0.0')]
final class TextareaField extends AbstractField
{
    public function __construct(
        string $name,
        string $label = '',
        private readonly ?int $rows = null,
        private readonly ?int $cols = null,
        private readonly ?int $maxLength = null,
        private readonly ?string $placeholder = null,
    ) {
        parent::__construct($name, $label);
    }

    #[Override]
    public function getType(): string
    {
        return 'textarea';
    }

    public function getRows(): ?int
    {
        return $this->rows;
    }

    public function getCols(): ?int
    {
        return $this->cols;
    }

    public function getMaxLength(): ?int
    {
        return $this->maxLength;
    }

    public function getPlaceholder(): ?string
    {
        return $this->placeholder;
    }
}
