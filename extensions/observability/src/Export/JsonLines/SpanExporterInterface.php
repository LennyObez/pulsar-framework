<?php

declare(strict_types=1);

namespace Pulsar\Extension\Observability\Export\JsonLines;

use Pulsar\Api\Api;
use Pulsar\Observability\Tracing\Span;

/**
 * Contract for exporting completed spans to an external destination.
 * @api
 */
#[Api(since: '1.0.0')]
interface SpanExporterInterface
{
    public function export(Span $span): void;

    public function flush(): void;

    public function shutdown(): void;
}
