<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation\Rule;

use Override;
use Pulsar\Api\Api;
use Pulsar\Http\Validation\RuleInterface;
use Pulsar\Http\Validation\Violation;

use function is_numeric;
use function sprintf;

/**
 * Value must be less than the given threshold. Skips null values.
 */
#[Api(since: '1.0.0')]
readonly class LessThan implements RuleInterface
{
    public function __construct(
        private int|float $threshold,
        private string $message = '',
    ) {}

    #[Override]
    public function validate(string $field, mixed $value, array $data): ?Violation
    {
        if ($value === null) {
            return null;
        }

        if (is_numeric($value) && (float) $value < $this->threshold) {
            return null;
        }

        return new Violation(
            field: $field,
            message: $this->message !== '' ? $this->message : sprintf('The %s field must be less than %s.', $field, $this->threshold),
            rule: $this->name(),
        );
    }

    #[Override]
    public function name(): string
    {
        return 'less_than';
    }
}
