<?php

declare(strict_types=1);

namespace Pulsar\Http\Http3;

use Pulsar\Api\Api;

/**
 * Fetch priority levels for resource loading hints.
 *
 * @see https://developer.mozilla.org/en-US/docs/Web/API/HTMLImageElement/fetchPriority
 */
#[Api(since: '1.0.0')]
enum FetchPriority: string
{
    /** Signal that the resource is high priority relative to same-type resources. */
    case High = 'high';

    /** Signal that the resource is low priority relative to same-type resources. */
    case Low = 'low';

    /** Let the browser determine priority (default). */
    case Auto = 'auto';
}
