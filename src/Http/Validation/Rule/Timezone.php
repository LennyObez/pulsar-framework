<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation\Rule;

use DateTimeZone;
use Override;
use Pulsar\Api\Api;
use Pulsar\Http\Validation\RuleInterface;
use Pulsar\Http\Validation\Violation;

use function in_array;
use function is_string;
use function sprintf;

/**
 * Value must be a valid timezone identifier. Skips null values.
 */
#[Api(since: '1.0.0')]
final readonly class Timezone implements RuleInterface
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

        if (is_string($value) && in_array($value, DateTimeZone::listIdentifiers(), true)) {
            return null;
        }

        return new Violation(
            field: $field,
            message: $this->message !== '' ? $this->message : sprintf('The %s field must be a valid timezone.', $field),
            rule: $this->name(),
        );
    }

    #[Override]
    public function name(): string
    {
        return 'timezone';
    }
}
