<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation\Filter;

use Override;
use Pulsar\Api\Api;

use function is_string;
use function strip_tags;

/**
 * Strips HTML/PHP tags from string values. Non-strings pass through unchanged.
 */
#[Api(since: '1.0.0')]
final readonly class StripTags implements FilterInterface
{
    #[Override]
    public function apply(mixed $value): mixed
    {
        return is_string($value) ? strip_tags($value) : $value;
    }
}
