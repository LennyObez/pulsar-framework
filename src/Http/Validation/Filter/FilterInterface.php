<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation\Filter;

use Pulsar\Api\Api;

/**
 * Contract for a single sanitization filter.
 * @api
 */
#[Api(since: '1.0.0')]
interface FilterInterface
{
    /**
     * Apply the filter to a value and return the sanitized result.
     */
    public function apply(mixed $value): mixed;
}
