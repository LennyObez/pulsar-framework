<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation\Rule;

use DateTimeImmutable;
use Override;
use Pulsar\Api\Api;
use Pulsar\Http\Validation\RuleInterface;
use Pulsar\Http\Validation\Violation;

use function is_string;
use function sprintf;

/**
 * Value must be a valid date matching the given format. Skips null values.
 */
#[Api(since: '1.0.0')]
final readonly class Date implements RuleInterface
{
    public function __construct(
        private string $format = 'Y-m-d',
        private string $message = '',
    ) {}

    #[Override]
    public function validate(string $field, mixed $value, array $data): ?Violation
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $parsed = DateTimeImmutable::createFromFormat($this->format, $value);
            if ($parsed !== false && $parsed->format($this->format) === $value) {
                return null;
            }
        }

        return new Violation(
            field: $field,
            message: $this->message !== '' ? $this->message : sprintf('The %s field must be a valid date.', $field),
            rule: $this->name(),
        );
    }

    #[Override]
    public function name(): string
    {
        return 'date';
    }
}
