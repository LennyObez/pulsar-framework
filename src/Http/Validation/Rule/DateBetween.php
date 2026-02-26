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
 * Value must be a date within the given inclusive range. Skips null values.
 */
#[Api(since: '1.0.0')]
readonly class DateBetween implements RuleInterface
{
    private DateTimeImmutable $fromDate;
    private DateTimeImmutable $toDate;

    public function __construct(
        string $from,
        string $to,
        private string $message = '',
    ) {
        $this->fromDate = new DateTimeImmutable($from);
        $this->toDate = new DateTimeImmutable($to);
    }

    #[Override]
    public function validate(string $field, mixed $value, array $data): ?Violation
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $parsed = new DateTimeImmutable($value);
            if ($parsed >= $this->fromDate && $parsed <= $this->toDate) {
                return null;
            }
        }

        return new Violation(
            field: $field,
            message: $this->message !== '' ? $this->message : sprintf(
                'The %s field must be a date between %s and %s.',
                $field,
                $this->fromDate->format('Y-m-d'),
                $this->toDate->format('Y-m-d'),
            ),
            rule: $this->name(),
        );
    }

    #[Override]
    public function name(): string
    {
        return 'date_between';
    }
}
