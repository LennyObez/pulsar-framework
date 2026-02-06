<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation\Filter;

use Override;
use Pulsar\Api\Api;

use function is_string;
use function mb_strtoupper;

/**
 * Converts string values to uppercase. Non-strings pass through unchanged.
 */
#[Api(since: '1.0.0')]
readonly class Uppercase implements FilterInterface
{
    #[Override]
    public function apply(mixed $value): mixed
    {
        return is_string($value) ? mb_strtoupper($value) : $value;
    }
}
