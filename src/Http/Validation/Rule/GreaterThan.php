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
 * Value must be greater than the given threshold. Skips null values.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class GreaterThan implements RuleInterface
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

        if (is_numeric($value) && (float) $value > $this->threshold) {
            return null;
        }

        return new Violation(
            field: $field,
            message: $this->message !== '' ? $this->message : sprintf('The %s field must be greater than %s.', $field, $this->threshold),
            rule: $this->name(),
        );
    }

    #[Override]
    public function name(): string
    {
        return 'greater_than';
    }
}
