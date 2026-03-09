<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation\Rule;

use Override;
use Pulsar\Api\Api;
use Pulsar\Http\Validation\TypeRuleInterface;
use Pulsar\Http\Validation\Violation;

use function is_string;
use function sprintf;

/**
 * Value must be a string. Skips null values.
 */
#[Api(since: '1.0.0')]
readonly class StringType implements TypeRuleInterface
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

        if (!is_string($value)) {
            return new Violation(
                field: $field,
                message: $this->message !== '' ? $this->message : sprintf('The %s field must be a string.', $field),
                rule: $this->name(),
            );
        }

        return null;
    }

    #[Override]
    public function name(): string
    {
        return 'string';
    }
}
