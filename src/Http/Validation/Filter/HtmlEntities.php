<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation\Filter;

use Override;
use Pulsar\Api\Api;

use function htmlspecialchars;
use function is_string;

/**
 * Encodes HTML special characters in string values. Non-strings pass through unchanged.
 */
#[Api(since: '1.0.0')]
readonly class HtmlEntities implements FilterInterface
{
    #[Override]
    public function apply(mixed $value): mixed
    {
        return is_string($value)
            ? htmlspecialchars($value)
            : $value;
    }
}
