<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation\Filter;

use Override;
use Pulsar\Api\Api;

use function is_string;
use function mb_strtolower;

/**
 * Converts string values to lowercase. Non-strings pass through unchanged.
 */
#[Api(since: '1.0.0')]
final readonly class Lowercase implements FilterInterface
{
    #[Override]
    public function apply(mixed $value): mixed
    {
        return is_string($value) ? mb_strtolower($value) : $value;
    }
}
