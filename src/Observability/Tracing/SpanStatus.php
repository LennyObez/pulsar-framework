<?php

declare(strict_types=1);

namespace Pulsar\Observability\Tracing;

use Pulsar\Api\Api;

/**
 * Status of a trace span.
 */
#[Api(since: '1.0.0')]
enum SpanStatus: string
{
    case Unset = 'unset';
    case Ok = 'ok';
    case Error = 'error';
}
