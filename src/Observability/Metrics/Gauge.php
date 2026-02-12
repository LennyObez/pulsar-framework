<?php

declare(strict_types=1);

namespace Pulsar\Observability\Metrics;

use Pulsar\Api\Api;

/**
 * Bidirectional gauge metric.
 *
 * Can go up and down. Use {@see set()}, {@see increment()}, or {@see decrement()}.
 */
#[Api(since: '1.0.0')]
final class Gauge
{
    /** @var array<string, float> label-key => value */
    private array $values = [];

    public function __construct(
        public readonly string $name,
        public readonly string $help = '',
    ) {}

    /**
     * Set the gauge to an absolute value.
     */
    public function set(float $value, LabelSet $labels = new LabelSet()): void
    {
        $this->values[$labels->key()] = $value;
    }

    /**
     * Increment the gauge by the given amount.
     */
    public function increment(LabelSet $labels = new LabelSet(), float $value = 1.0): void
    {
        $key = $labels->key();
        $this->values[$key] = ($this->values[$key] ?? 0.0) + $value;
    }

    /**
     * Decrement the gauge by the given amount.
     */
    public function decrement(LabelSet $labels = new LabelSet(), float $value = 1.0): void
    {
        $key = $labels->key();
        $this->values[$key] = ($this->values[$key] ?? 0.0) - $value;
    }

    /**
     * Get the current value for a label set.
     */
    public function value(LabelSet $labels = new LabelSet()): float
    {
        return $this->values[$labels->key()] ?? 0.0;
    }

    /**
     * Get all label-key => value pairs.
     *
     * @return array<string, float>
     */
    public function values(): array
    {
        return $this->values;
    }

    /**
     * Reset all values to zero.
     */
    public function reset(): void
    {
        $this->values = [];
    }
}
