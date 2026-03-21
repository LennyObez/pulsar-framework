<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation\Rule;

use Override;
use Pulsar\Api\Api;
use Pulsar\Http\Validation\RuleInterface;
use Pulsar\Http\Validation\Violation;

use function is_string;
use function sprintf;
use function str_starts_with;

/**
 * Value must start with the given prefix. Skips null values.
 */
#[Api(since: '1.0.0')]
final readonly class StartsWith implements RuleInterface
{
    public function __construct(
        private string $prefix,
        private string $message = '',
    ) {}

    #[Override]
    public function validate(string $field, mixed $value, array $data): ?Violation
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value) && str_starts_with($value, $this->prefix)) {
            return null;
        }

        return new Violation(
            field: $field,
            message: $this->message !== '' ? $this->message : sprintf('The %s field must start with "%s".', $field, $this->prefix),
            rule: $this->name(),
        );
    }

    #[Override]
    public function name(): string
    {
        return 'starts_with';
    }
}
