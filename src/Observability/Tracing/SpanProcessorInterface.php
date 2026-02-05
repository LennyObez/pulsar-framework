<?php

declare(strict_types=1);

namespace Pulsar\Observability\Tracing;

use Pulsar\Api\Api;

/**
 * Contract for processing completed spans.
 */
#[Api]
interface SpanProcessorInterface
{
    /**
     * Called when a span ends.
     */
    public function onEnd(Span $span): void;
}
