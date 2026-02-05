<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation\Rule;

use function is_string;
use function preg_match;

use Pulsar\Api\Api;
use Pulsar\Http\Validation\RuleInterface;
use Pulsar\Http\Validation\Violation;

use function sprintf;

/**
 * Value must match the given regular expression. Skips null values.
 */
#[Api]
readonly class Regex implements RuleInterface
{
    public function __construct(
        private string $pattern,
        private string $message = '',
    ) {}

    public function validate(string $field, mixed $value, array $data): ?Violation
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value) && preg_match($this->pattern, $value) === 1) {
            return null;
        }

        return new Violation(
            field: $field,
            message: $this->message !== '' ? $this->message : sprintf('The %s field format is invalid.', $field),
            rule: $this->name(),
        );
    }

    public function name(): string
    {
        return 'regex';
    }
}
