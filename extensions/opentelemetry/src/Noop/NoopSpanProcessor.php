<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Noop;

use Override;
use Pulsar\Api\Api;
use Pulsar\Observability\Tracing\Span;
use Pulsar\Observability\Tracing\SpanProcessorInterface;

/**
 * No-operation span processor that silently discards all spans.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class NoopSpanProcessor implements SpanProcessorInterface
{
    #[Override]
    public function onEnd(Span $span): void
    {
        // Intentionally empty.
    }
}
