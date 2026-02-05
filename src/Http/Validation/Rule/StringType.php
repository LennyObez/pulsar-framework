<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation\Rule;

use function is_string;

use Pulsar\Api\Api;
use Pulsar\Http\Validation\RuleInterface;
use Pulsar\Http\Validation\Violation;

use function sprintf;

/**
 * Value must be a string. Skips null values.
 */
#[Api]
readonly class StringType implements RuleInterface
{
    public function __construct(
        private string $message = '',
    ) {}

    public function validate(string $field, mixed $value, array $data): ?Violation
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            return new Violation(
                field: $field,
                message: $this->message !== '' ? $this->message : sprintf('The %s field must be a string.', $field),
                rule: $this->name(),
            );
        }

        return null;
    }

    public function name(): string
    {
        return 'string';
    }
}
