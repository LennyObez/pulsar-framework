<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation\Rule;

use const FILTER_VALIDATE_EMAIL;

use function filter_var;

use Pulsar\Api\Api;
use Pulsar\Http\Validation\RuleInterface;
use Pulsar\Http\Validation\Violation;

use function sprintf;

/**
 * Value must be a valid email address via FILTER_VALIDATE_EMAIL. Skips null values.
 */
#[Api]
readonly class Email implements RuleInterface
{
    public function __construct(
        private string $message = '',
    ) {}

    public function validate(string $field, mixed $value, array $data): ?Violation
    {
        if ($value === null) {
            return null;
        }

        if (filter_var($value, FILTER_VALIDATE_EMAIL) !== false) {
            return null;
        }

        return new Violation(
            field: $field,
            message: $this->message !== '' ? $this->message : sprintf('The %s field must be a valid email address.', $field),
            rule: $this->name(),
        );
    }

    public function name(): string
    {
        return 'email';
    }
}
