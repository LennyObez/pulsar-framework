<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Field;

use Override;
use Pulsar\Api\Api;

/**
 * Password input field (never pre-populated for security).
 */
#[Api(since: '1.0.0')]
final class PasswordField extends AbstractField
{
    public function __construct(
        string $name,
        string $label = '',
        private readonly ?int $minLength = null,
        private readonly ?string $placeholder = null,
    ) {
        parent::__construct($name, $label);
    }

    #[Override]
    public function getType(): string
    {
        return 'password';
    }

    /**
     * Password fields never expose their value for security.
     */
    #[Override]
    public function getValue(): null
    {
        return null;
    }

    public function getMinLength(): ?int
    {
        return $this->minLength;
    }

    public function getPlaceholder(): ?string
    {
        return $this->placeholder;
    }
}
