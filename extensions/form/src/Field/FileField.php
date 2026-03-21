<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Field;

use Override;
use Pulsar\Api\Api;

/**
 * File upload input field.
 * @api
 */
#[Api(since: '1.0.0')]
final class FileField extends AbstractField
{
    /**
     * @param list<string> $allowedMimeTypes Allowed MIME types (validated by magic bytes)
     */
    public function __construct(
        string $name,
        string $label = '',
        private readonly array $allowedMimeTypes = [],
        private readonly ?int $maxSize = null,
        private readonly bool $multiple = false,
        private readonly ?string $accept = null,
    ) {
        parent::__construct($name, $label);
    }

    #[Override]
    public function getType(): string
    {
        return 'file';
    }

    /**
     * @return list<string>
     */
    public function getAllowedMimeTypes(): array
    {
        return $this->allowedMimeTypes;
    }

    public function getMaxSize(): ?int
    {
        return $this->maxSize;
    }

    public function isMultiple(): bool
    {
        return $this->multiple;
    }

    public function getAccept(): ?string
    {
        return $this->accept;
    }
}
