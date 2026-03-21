<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Field;

use Override;
use Pulsar\Api\Api;

/**
 * Checkbox input field.
 * @api
 */
#[Api(since: '1.0.0')]
final class CheckboxField extends AbstractField
{
    public function __construct(
        string $name,
        string $label = '',
        private readonly string $checkedValue = '1',
    ) {
        parent::__construct($name, $label);
    }

    #[Override]
    public function getType(): string
    {
        return 'checkbox';
    }

    public function getCheckedValue(): string
    {
        return $this->checkedValue;
    }

    public function isChecked(): bool
    {
        if ($this->value === null) {
            return false;
        }

        /** @var string|int|float|bool $val */
        $val = $this->value;

        return (string) $val === $this->checkedValue;
    }
}
