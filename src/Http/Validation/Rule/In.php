<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation\Rule;

use function array_map;
use function implode;
use function in_array;
use function is_scalar;

use Pulsar\Http\Validation\RuleInterface;
use Pulsar\Http\Validation\Violation;

use function sprintf;
use function strval;

/**
 * Value must be one of an allowed set (loose comparison for HTTP string inputs).
 * Skips null values.
 */
readonly class In implements RuleInterface
{
    /**
     * @param list<string|int|float> $allowed
     */
    public function __construct(
        private array $allowed,
        private string $message = '',
    ) {}

    public function validate(string $field, mixed $value, array $data): ?Violation
    {
        if ($value === null) {
            return null;
        }

        // Loose comparison: cast allowed values to string for HTTP input matching
        if (is_scalar($value)) {
            $stringAllowed = array_map(strval(...), $this->allowed);
            if (in_array(strval($value), $stringAllowed, true)) {
                return null;
            }
        }

        return new Violation(
            field: $field,
            message: $this->message !== '' ? $this->message : sprintf(
                'The %s field must be one of: %s.',
                $field,
                implode(', ', array_map(strval(...), $this->allowed)),
            ),
            rule: $this->name(),
        );
    }

    public function name(): string
    {
        return 'in';
    }
}
