<?php

declare(strict_types=1);

namespace Pulsar\Observability\Metrics;

use Pulsar\Observability\Metrics\Exception\MetricsException;

/**
 * Monotonic counter metric.
 *
 * Counters only go up. Use {@see increment()} to add a non-negative value.
 */
final class Counter
{
    /** @var array<string, float> label-key => value */
    private array $values = [];

    public function __construct(
        public readonly string $name,
        public readonly string $help = '',
    ) {}

    /**
     * Increment the counter for the given label set.
     *
     * @throws MetricsException If $value is negative
     */
    public function increment(LabelSet $labels = new LabelSet(), float $value = 1.0): void
    {
        if ($value < 0) {
            throw MetricsException::negativeIncrement($value);
        }

        $key = $labels->key();
        $this->values[$key] = ($this->values[$key] ?? 0.0) + $value;
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
