<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation\Rule;

use DateTimeImmutable;
use DateTimeInterface;
use Override;
use Pulsar\Api\Api;
use Pulsar\Http\Validation\RuleInterface;
use Pulsar\Http\Validation\Violation;

use function is_string;
use function sprintf;

/**
 * Value must be a date after the given boundary date. Skips null values.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class After implements RuleInterface
{
    private DateTimeImmutable $boundary;

    public function __construct(
        string|DateTimeInterface $date,
        private string $message = '',
    ) {
        $this->boundary = $date instanceof DateTimeInterface
            ? DateTimeImmutable::createFromInterface($date)
            : new DateTimeImmutable($date);
    }

    #[Override]
    public function validate(string $field, mixed $value, array $data): ?Violation
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $parsed = new DateTimeImmutable($value);
            if ($parsed > $this->boundary) {
                return null;
            }
        }

        return new Violation(
            field: $field,
            message: $this->message !== '' ? $this->message : sprintf(
                'The %s field must be a date after %s.',
                $field,
                $this->boundary->format('Y-m-d'),
            ),
            rule: $this->name(),
        );
    }

    #[Override]
    public function name(): string
    {
        return 'after';
    }
}
