<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation\Rule;

use Override;
use Pulsar\Api\Api;
use Pulsar\Http\Validation\RuleInterface;
use Pulsar\Http\Validation\Violation;

use function in_array;
use function is_array;
use function sprintf;

/**
 * All array elements must be unique (strict comparison). Skips null values.
 */
#[Api(since: '1.0.0')]
readonly class Distinct implements RuleInterface
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

        if (!is_array($value)) {
            return new Violation(
                field: $field,
                message: $this->message !== '' ? $this->message : sprintf('The %s field must be an array.', $field),
                rule: $this->name(),
            );
        }

        $unique = [];

        foreach ($value as $element) {
            if (in_array($element, $unique, true)) {
                return new Violation(
                    field: $field,
                    message: $this->message !== '' ? $this->message : sprintf(
                        'The %s field must not contain duplicate values.',
                        $field,
                    ),
                    rule: $this->name(),
                );
            }

            $unique[] = $element;
        }

        return null;
    }

    #[Override]
    public function name(): string
    {
        return 'distinct';
    }
}
