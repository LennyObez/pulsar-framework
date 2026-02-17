<?php

declare(strict_types=1);

namespace Pulsar\Extension\Observability\Tracing\Bridge;

use Override;
use Pulsar\Api\Api;
use Pulsar\Observability\Tracing\Span;
use Pulsar\Observability\Tracing\SpanProcessorInterface;

/**
 * Dispatches span lifecycle events to multiple processors.
 */
#[Api(since: '1.0.0')]
final readonly class CompositeSpanProcessor implements SpanProcessorInterface
{
    /** @var list<SpanProcessorInterface> */
    private array $processors;

    /**
     * @param list<SpanProcessorInterface> $processors
     */
    public function __construct(array $processors = [])
    {
        $this->processors = $processors;
    }

    #[Override]
    public function onEnd(Span $span): void
    {
        foreach ($this->processors as $processor) {
            $processor->onEnd($span);
        }
    }
}
