<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation\Filter;

use Override;
use Pulsar\Api\Api;

use function is_string;
use function trim;

/**
 * Trims whitespace from string values. Non-strings pass through unchanged.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class Trim implements FilterInterface
{
    #[Override]
    public function apply(mixed $value): mixed
    {
        return is_string($value) ? trim($value) : $value;
    }
}
