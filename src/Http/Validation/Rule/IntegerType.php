<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation\Rule;

use const FILTER_VALIDATE_INT;

use function filter_var;
use function is_bool;
use function is_int;

use Override;
use Pulsar\Api\Api;
use Pulsar\Http\Validation\RuleInterface;
use Pulsar\Http\Validation\Violation;

use function sprintf;

/**
 * Value must be an integer or a numeric string that passes FILTER_VALIDATE_INT.
 * Skips null values.
 */
#[Api(since: '1.0.0')]
readonly class IntegerType implements RuleInterface
{
    public function __construct(
        private string $message = '',
    ) {}

    #[Override]
    public function validate(string $field, mixed $value, array $data): ?Violation
    {
        if ($value === null) {
            return null;
        }

        // Booleans are not integers even though filter_var would accept them
        if (is_bool($value)) {
            return new Violation(
                field: $field,
                message: $this->message !== '' ? $this->message : sprintf('The %s field must be an integer.', $field),
                rule: $this->name(),
            );
        }

        if (is_int($value)) {
            return null;
        }

        if (filter_var($value, FILTER_VALIDATE_INT) !== false) {
            return null;
        }

        return new Violation(
            field: $field,
            message: $this->message !== '' ? $this->message : sprintf('The %s field must be an integer.', $field),
            rule: $this->name(),
        );
    }

    #[Override]
    public function name(): string
    {
        return 'integer';
    }
}
