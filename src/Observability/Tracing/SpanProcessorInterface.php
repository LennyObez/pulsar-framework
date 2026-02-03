<?php

declare(strict_types=1);

namespace Pulsar\Observability\Tracing;

/**
 * Contract for processing completed spans.
 */
interface SpanProcessorInterface
{
    /**
     * Called when a span ends.
     */
    public function onEnd(Span $span): void;
}
