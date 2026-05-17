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
 * Value must be identical to another field's value (strict comparison). Skips null values.
 */
#[Api(since: '1.0.0')]
final readonly class Same implements RuleInterface
{
    public function __construct(
        private string $otherField,
        private string $message = '',
    ) {}

    #[Override]
    public function validate(string $field, mixed $value, array $data): ?Violation
    {
        if ($value === null) {
            return null;
        }

        if (array_key_exists($this->otherField, $data) && $value === $data[$this->otherField]) {
            return null;
        }

        return new Violation(
            field: $field,
            message: $this->message !== '' ? $this->message : sprintf(
                'The %s field must match %s.',
                $field,
                $this->otherField,
            ),
            rule: $this->name(),
        );
    }

    #[Override]
    public function name(): string
    {
        return 'same';
    }
}
