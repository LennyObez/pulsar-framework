<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation\Rule;

use Override;
use Pulsar\Api\Api;
use Pulsar\Http\Validation\RuleInterface;
use Pulsar\Http\Validation\Violation;

/**
 * Marker rule that explicitly allows null values. Always passes.
 */
#[Api(since: '1.0.0')]
readonly class Nullable implements RuleInterface
{
    #[Override]
    public function validate(string $field, mixed $value, array $data): ?Violation
    {
        return null;
    }

    #[Override]
    public function name(): string
    {
        return 'nullable';
    }
}
