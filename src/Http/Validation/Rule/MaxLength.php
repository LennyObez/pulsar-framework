<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation\Rule;

use function is_string;
use function mb_strlen;

use Pulsar\Api\Api;
use Pulsar\Http\Validation\RuleInterface;
use Pulsar\Http\Validation\Violation;

use function sprintf;

/**
 * String length must be <= the given maximum. Skips null values.
 */
#[Api]
readonly class MaxLength implements RuleInterface
{
    public function __construct(
        private int $max,
        private string $message = '',
    ) {}

    public function validate(string $field, mixed $value, array $data): ?Violation
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value) && mb_strlen($value) <= $this->max) {
            return null;
        }

        return new Violation(
            field: $field,
            message: $this->message !== '' ? $this->message : sprintf('The %s field must not exceed %d characters.', $field, $this->max),
            rule: $this->name(),
        );
    }

    public function name(): string
    {
        return 'max_length';
    }
}
