<?php

declare(strict_types=1);

namespace Pulsar\Extension\ObservabilityExport\Span;

use Pulsar\Api\Api;
use Pulsar\Observability\Tracing\Span;

/**
 * Contract for exporting completed spans to an external destination.
 */
#[Api]
interface SpanExporterInterface
{
    /**
     * Export a single completed span.
     */
    public function export(Span $span): void;

    /**
     * Flush any buffered spans to the destination.
     */
    public function flush(): void;

    /**
     * Shut down the exporter, flushing remaining data and releasing resources.
     */
    public function shutdown(): void;
}
