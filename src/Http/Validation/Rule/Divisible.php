<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation\Rule;

use InvalidArgumentException;
use Override;
use Pulsar\Api\Api;
use Pulsar\Http\Validation\RuleInterface;
use Pulsar\Http\Validation\Violation;

use function fmod;
use function is_numeric;
use function sprintf;

/**
 * Value must be divisible by the given divisor. Skips null values.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class Divisible implements RuleInterface
{
    public function __construct(
        private int|float $divisor,
        private string $message = '',
    ) {
        if ($this->divisor == 0) {
            throw new InvalidArgumentException('Divisor must not be zero.');
        }
    }

    #[Override]
    public function validate(string $field, mixed $value, array $data): ?Violation
    {
        if ($value === null) {
            return null;
        }

        if (is_numeric($value) && fmod((float) $value, (float) $this->divisor) == 0.0) {
            return null;
        }

        return new Violation(
            field: $field,
            message: $this->message !== '' ? $this->message : sprintf('The %s field must be divisible by %s.', $field, $this->divisor),
            rule: $this->name(),
        );
    }

    #[Override]
    public function name(): string
    {
        return 'divisible';
    }
}
