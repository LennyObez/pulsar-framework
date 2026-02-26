<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation\Rule;

use Override;
use Pulsar\Api\Api;
use Pulsar\Http\Validation\RuleInterface;
use Pulsar\Http\Validation\Violation;

use function is_string;
use function sprintf;
use function str_contains;

/**
 * Value must contain the given substring. Skips null values.
 */
#[Api(since: '1.0.0')]
readonly class Contains implements RuleInterface
{
    public function __construct(
        private string $needle,
        private string $message = '',
    ) {}

    #[Override]
    public function validate(string $field, mixed $value, array $data): ?Violation
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value) && str_contains($value, $this->needle)) {
            return null;
        }

        return new Violation(
            field: $field,
            message: $this->message !== '' ? $this->message : sprintf('The %s field must contain "%s".', $field, $this->needle),
            rule: $this->name(),
        );
    }

    #[Override]
    public function name(): string
    {
        return 'contains';
    }
}
