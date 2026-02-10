<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Field;

use Override;
use Pulsar\Api\Api;

/**
 * Radio button group field.
 */
#[Api(since: '1.0.0')]
final class RadioField extends AbstractField
{
    /**
     * @param array<string, string> $options Value => label pairs
     */
    public function __construct(
        string $name,
        string $label = '',
        private array $options = [],
    ) {
        parent::__construct($name, $label);
    }

    #[Override]
    public function getType(): string
    {
        return 'radio';
    }

    /**
     * @return array<string, string>
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * @param array<string, string> $options
     */
    public function setOptions(array $options): self
    {
        $this->options = $options;

        return $this;
    }
}
