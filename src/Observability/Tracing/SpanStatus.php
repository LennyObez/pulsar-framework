<?php

declare(strict_types=1);

namespace Pulsar\Observability\Tracing;

/**
 * Status of a trace span.
 */
enum SpanStatus: string
{
    case Unset = 'unset';
    case Ok = 'ok';
    case Error = 'error';
}
