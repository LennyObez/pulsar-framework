<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation\Rule;

use Override;
use Pulsar\Api\Api;
use Pulsar\Http\Validation\RuleInterface;
use Pulsar\Http\Validation\Violation;

use function is_numeric;
use function sprintf;
use function str_contains;
use function strlen;
use function strrchr;

/**
 * Validates the number of decimal places. Skips null values.
 */
#[Api(since: '1.0.0')]
final readonly class Decimal implements RuleInterface
{
    private int $maxPlaces;

    public function __construct(
        private int $min,
        int $max = -1,
        private string $message = '',
    ) {
        $this->maxPlaces = $max === -1 ? $this->min : $max;
    }

    #[Override]
    public function validate(string $field, mixed $value, array $data): ?Violation
    {
        if ($value === null) {
            return null;
        }

        if (!is_numeric($value)) {
            return $this->fail($field);
        }

        $stringValue = (string) $value;
        $places = 0;

        if (str_contains($stringValue, '.')) {
            $decimal = strrchr($stringValue, '.');
            $places = $decimal !== false ? strlen($decimal) - 1 : 0;
        }

        if ($places >= $this->min && $places <= $this->maxPlaces) {
            return null;
        }

        return $this->fail($field);
    }

    #[Override]
    public function name(): string
    {
        return 'decimal';
    }

    private function fail(string $field): Violation
    {
        $constraint = $this->min === $this->maxPlaces
            ? sprintf('%d', $this->min)
            : sprintf('%d-%d', $this->min, $this->maxPlaces);

        return new Violation(
            field: $field,
            message: $this->message !== '' ? $this->message : sprintf(
                'The %s field must have %s decimal places.',
                $field,
                $constraint,
            ),
            rule: $this->name(),
        );
    }
}
