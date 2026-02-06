<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation\Rule;

use Override;
use Pulsar\Api\Api;
use Pulsar\Http\Validation\RuleInterface;
use Pulsar\Http\Validation\Violation;

use function array_key_exists;
use function sprintf;

/**
 * Value must match {field}_confirmation in data. Skips null values.
 */
#[Api(since: '1.0.0')]
readonly class Confirmed implements RuleInterface
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

        $confirmationField = $field . '_confirmation';

        if (array_key_exists($confirmationField, $data) && $value === $data[$confirmationField]) {
            return null;
        }

        return new Violation(
            field: $field,
            message: $this->message !== '' ? $this->message : sprintf(
                'The %s confirmation does not match.',
                $field,
            ),
            rule: $this->name(),
        );
    }

    #[Override]
    public function name(): string
    {
        return 'confirmed';
    }
}
