<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation\Rule;

use Override;
use Pulsar\Api\Api;
use Pulsar\Http\Validation\RuleInterface;
use Pulsar\Http\Validation\Violation;

use function is_string;
use function mb_strlen;
use function sprintf;

/**
 * String length must be >= the given minimum. Skips null values.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class MinLength implements RuleInterface
{
    public function __construct(
        private int $min,
        private string $message = '',
    ) {}

    #[Override]
    public function validate(string $field, mixed $value, array $data): ?Violation
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value) && mb_strlen($value) >= $this->min) {
            return null;
        }

        return new Violation(
            field: $field,
            message: $this->message !== '' ? $this->message : sprintf('The %s field must be at least %d characters.', $field, $this->min),
            rule: $this->name(),
        );
    }

    #[Override]
    public function name(): string
    {
        return 'min_length';
    }
}
