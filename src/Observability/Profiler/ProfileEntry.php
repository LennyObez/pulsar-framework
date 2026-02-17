<?php

declare(strict_types=1);

namespace Pulsar\Observability\Profiler;

use Pulsar\Api\Api;

/**
 * A single timing entry in the request profiler timeline.
 */
#[Api(since: '1.0.0')]
final readonly class ProfileEntry
{
    /**
     * @param string $category Category (middleware, routing, controller, database, view, custom)
     * @param string $label Human-readable label
     * @param int $startNs Start time in nanoseconds (hrtime)
     * @param int $endNs End time in nanoseconds (hrtime)
     * @param array<string, scalar> $metadata Additional context
     */
    public function __construct(
        public string $category,
        public string $label,
        public int $startNs,
        public int $endNs,
        public array $metadata = [],
    ) {}

    /**
     * Duration in nanoseconds.
     */
    public function durationNs(): int
    {
        return $this->endNs - $this->startNs;
    }

    /**
     * Duration in milliseconds.
     */
    public function durationMs(): float
    {
        return $this->durationNs() / 1_000_000;
    }

    /**
     * Serialize to array for Studio dashboard / JSON API.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'category' => $this->category,
            'label' => $this->label,
            'start_ns' => $this->startNs,
            'end_ns' => $this->endNs,
            'duration_ms' => round($this->durationMs(), 3),
            'metadata' => $this->metadata,
        ];
    }
}
