<?php

declare(strict_types=1);

namespace Pulsar\Observability\Tracing;

use function hrtime;

/**
 * Mutable lifecycle object representing a unit of work in a trace.
 *
 * Spans track name, start/end times (via hrtime), attributes, and status.
 * Calling {@see end()} is idempotent.
 */
final class Span
{
    private int $startTime;
    private ?int $endTime = null;

    /** @var array<string, scalar> */
    private array $attributes = [];
    private SpanStatus $status = SpanStatus::Unset;

    public function __construct(
        public readonly string $name,
        public readonly TraceContext $context,
        public readonly ?SpanId $parentSpanId = null,
    ) {
        $this->startTime = hrtime(true);
    }

    /**
     * End this span. Idempotent — subsequent calls are no-ops.
     */
    public function end(): void
    {
        if ($this->endTime === null) {
            $this->endTime = hrtime(true);
        }
    }

    /**
     * Set the span status.
     */
    public function setStatus(SpanStatus $status): void
    {
        $this->status = $status;
    }

    /**
     * Set a span attribute.
     */
    public function setAttribute(string $key, string|int|float|bool $value): void
    {
        $this->attributes[$key] = $value;
    }

    /**
     * Get the span status.
     */
    public function status(): SpanStatus
    {
        return $this->status;
    }

    /**
     * Get start time in nanoseconds (hrtime).
     */
    public function startTime(): int
    {
        return $this->startTime;
    }

    /**
     * Get end time in nanoseconds (hrtime), or null if not ended.
     */
    public function endTime(): ?int
    {
        return $this->endTime;
    }

    /**
     * Get the duration in nanoseconds, or null if not ended.
     */
    public function duration(): ?int
    {
        if ($this->endTime === null) {
            return null;
        }

        return $this->endTime - $this->startTime;
    }

    /**
     * Get the duration in seconds as a float, or null if not ended.
     */
    public function durationSeconds(): ?float
    {
        $duration = $this->duration();

        if ($duration === null) {
            return null;
        }

        return $duration / 1_000_000_000;
    }

    /**
     * Check if this span has ended.
     */
    public function hasEnded(): bool
    {
        return $this->endTime !== null;
    }

    /**
     * @return array<string, scalar>
     */
    public function attributes(): array
    {
        return $this->attributes;
    }
}
