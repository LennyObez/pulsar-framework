<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation\Rule;

use function is_numeric;

use Pulsar\Http\Validation\RuleInterface;
use Pulsar\Http\Validation\Violation;

use function sprintf;

/**
 * Numeric value must be <= the given maximum. Skips null values.
 */
readonly class Max implements RuleInterface
{
    public function __construct(
        private int|float $max,
        private string $message = '',
    ) {}

    public function validate(string $field, mixed $value, array $data): ?Violation
    {
        if ($value === null) {
            return null;
        }

        if (!is_numeric($value)) {
            return new Violation(
                field: $field,
                message: $this->message !== '' ? $this->message : sprintf('The %s field must be at most %s.', $field, (string) $this->max),
                rule: $this->name(),
            );
        }

        if ((float) $value > $this->max) {
            return new Violation(
                field: $field,
                message: $this->message !== '' ? $this->message : sprintf('The %s field must be at most %s.', $field, (string) $this->max),
                rule: $this->name(),
            );
        }

        return null;
    }

    public function name(): string
    {
        return 'max';
    }
}
