<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation\Rule;

use function is_numeric;

use Pulsar\Api\Api;
use Pulsar\Http\Validation\RuleInterface;
use Pulsar\Http\Validation\Violation;

use function sprintf;

/**
 * Numeric value must be within inclusive range [min, max]. Skips null values.
 */
#[Api]
readonly class Between implements RuleInterface
{
    public function __construct(
        private int|float $min,
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
                message: $this->message !== '' ? $this->message : sprintf(
                    'The %s field must be between %s and %s.',
                    $field,
                    $this->min,
                    $this->max,
                ),
                rule: $this->name(),
            );
        }

        $numeric = (float) $value;

        if ($numeric < $this->min || $numeric > $this->max) {
            return new Violation(
                field: $field,
                message: $this->message !== '' ? $this->message : sprintf(
                    'The %s field must be between %s and %s.',
                    $field,
                    $this->min,
                    $this->max,
                ),
                rule: $this->name(),
            );
        }

        return null;
    }

    public function name(): string
    {
        return 'between';
    }
}
