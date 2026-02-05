<?php

declare(strict_types=1);

namespace Pulsar\Observability\Tracing;

use Pulsar\Api\Api;

/**
 * Status of a trace span.
 */
#[Api]
enum SpanStatus: string
{
    case Unset = 'unset';
    case Ok = 'ok';
    case Error = 'error';
}
