<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation\Rule;

use Override;
use Pulsar\Api\Api;
use Pulsar\Http\Validation\RuleInterface;
use Pulsar\Http\Validation\Violation;

use function count;
use function is_array;
use function is_string;
use function mb_strlen;
use function sprintf;

/**
 * Exact count for arrays (count()) or exact length for strings (mb_strlen()).
 * Skips null values.
 */
#[Api(since: '1.0.0')]
readonly class Size implements RuleInterface
{
    public function __construct(
        private int $size,
        private string $message = '',
    ) {}

    #[Override]
    public function validate(string $field, mixed $value, array $data): ?Violation
    {
        if ($value === null) {
            return null;
        }

        $actual = match (true) {
            is_array($value) => count($value),
            is_string($value) => mb_strlen($value),
            default => null,
        };

        if ($actual === $this->size) {
            return null;
        }

        return new Violation(
            field: $field,
            message: $this->message !== '' ? $this->message : sprintf(
                'The %s field must be exactly %d.',
                $field,
                $this->size,
            ),
            rule: $this->name(),
        );
    }

    #[Override]
    public function name(): string
    {
        return 'size';
    }
}
