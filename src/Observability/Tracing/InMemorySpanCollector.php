<?php

declare(strict_types=1);

namespace Pulsar\Observability\Tracing;

use function array_filter;
use function array_slice;
use function array_values;
use function count;

/**
 * In-memory span collector with ring-buffer eviction.
 *
 * Stores completed spans up to a configurable maximum. When full,
 * the oldest spans are evicted.
 */
final class InMemorySpanCollector implements SpanProcessorInterface
{
    /** @var list<Span> */
    private array $spans = [];

    public function __construct(
        private readonly int $maxSpans = 1000,
    ) {}

    public function onEnd(Span $span): void
    {
        $this->spans[] = $span;

        // Evict oldest when exceeding capacity
        if (count($this->spans) > $this->maxSpans) {
            $this->spans = array_slice($this->spans, -$this->maxSpans);
        }
    }

    /**
     * Get all collected spans.
     *
     * @return list<Span>
     */
    public function spans(): array
    {
        return $this->spans;
    }

    /**
     * Get spans belonging to a specific trace.
     *
     * @return list<Span>
     */
    public function spansByTraceId(TraceId $traceId): array
    {
        return array_values(array_filter(
            $this->spans,
            static fn(Span $span): bool => $span->context->traceId->value === $traceId->value,
        ));
    }

    /**
     * Get the number of collected spans.
     */
    public function count(): int
    {
        return count($this->spans);
    }

    /**
     * Clear all collected spans.
     */
    public function clear(): void
    {
        $this->spans = [];
    }
}
