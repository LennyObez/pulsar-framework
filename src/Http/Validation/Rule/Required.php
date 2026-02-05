<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation\Rule;

use Pulsar\Api\Api;
use Pulsar\Http\Validation\RuleInterface;
use Pulsar\Http\Validation\Violation;

use function sprintf;

/**
 * Fails on null, empty string, or empty array.
 */
#[Api]
readonly class Required implements RuleInterface
{
    public function __construct(
        private string $message = '',
    ) {}

    public function validate(string $field, mixed $value, array $data): ?Violation
    {
        $missing = $value === null
            || $value === ''
            || $value === [];

        if ($missing) {
            return new Violation(
                field: $field,
                message: $this->message !== '' ? $this->message : sprintf('The %s field is required.', $field),
                rule: $this->name(),
            );
        }

        return null;
    }

    public function name(): string
    {
        return 'required';
    }
}
