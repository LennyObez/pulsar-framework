<?php

declare(strict_types=1);

namespace Pulsar\Observability\Tracing;

use Random\RandomException;

/**
 * Readonly value object representing distributed trace context.
 *
 * Holds trace ID, span ID, and trace flags. Creates child contexts
 * that preserve the trace ID with a new span ID.
 */
final readonly class TraceContext
{
    public function __construct(
        public TraceId $traceId,
        public SpanId $spanId,
        public int $traceFlags = 0x01,
    ) {}

    /**
     * Create a child context with a new span ID, preserving the trace ID.
     *
     * @throws RandomException
     */
    public function createChild(): self
    {
        return new self(
            traceId: $this->traceId,
            spanId: SpanId::generate(),
            traceFlags: $this->traceFlags,
        );
    }

    /**
     * Check if this context is sampled (flag bit 0).
     */
    public function isSampled(): bool
    {
        return ($this->traceFlags & 0x01) !== 0;
    }

    /**
     * Create a new root trace context.
     *
     * @throws RandomException
     */
    public static function create(): self
    {
        return new self(
            traceId: TraceId::generate(),
            spanId: SpanId::generate(),
        );
    }
}
