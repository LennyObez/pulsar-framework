<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation\Rule;

use function is_numeric;

use Override;
use Pulsar\Api\Api;
use Pulsar\Http\Validation\RuleInterface;
use Pulsar\Http\Validation\Violation;

use function sprintf;

/**
 * Numeric value must be <= the given maximum. Skips null values.
 */
#[Api]
readonly class Max implements RuleInterface
{
    public function __construct(
        private int|float $max,
        private string $message = '',
    ) {}

    #[Override]
    public function validate(string $field, mixed $value, array $data): ?Violation
    {
        if ($value === null) {
            return null;
        }

        if (!is_numeric($value)) {
            return new Violation(
                field: $field,
                message: $this->message !== '' ? $this->message : sprintf('The %s field must be at most %s.', $field, $this->max),
                rule: $this->name(),
            );
        }

        if ((float) $value > $this->max) {
            return new Violation(
                field: $field,
                message: $this->message !== '' ? $this->message : sprintf('The %s field must be at most %s.', $field, $this->max),
                rule: $this->name(),
            );
        }

        return null;
    }

    #[Override]
    public function name(): string
    {
        return 'max';
    }
}
