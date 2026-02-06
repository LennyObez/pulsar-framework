<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation\Rule;

use Override;
use Pulsar\Api\Api;
use Pulsar\Http\Validation\RuleInterface;
use Pulsar\Http\Validation\Violation;

use function sprintf;

/**
 * Value must be an instance of the specified class. Skips null values.
 */
#[Api(since: '1.0.0')]
readonly class InstanceOfRule implements RuleInterface
{
    public function __construct(
        private string $className,
        private string $message = '',
    ) {}

    #[Override]
    public function validate(string $field, mixed $value, array $data): ?Violation
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof $this->className) {
            return null;
        }

        return new Violation(
            field: $field,
            message: $this->message !== '' ? $this->message : sprintf(
                'The %s field must be an instance of %s.',
                $field,
                $this->className,
            ),
            rule: $this->name(),
        );
    }

    #[Override]
    public function name(): string
    {
        return 'instance_of';
    }
}
