<?php

declare(strict_types=1);

namespace Pulsar\Observability\Metrics;

use function count;
use function sort;

use const SORT_NUMERIC;

/**
 * Bucket-based histogram metric.
 *
 * Records observed values into configurable buckets and tracks sum/count.
 *
 * Bucket keys are the string representation of boundary floats. PHP may
 * auto-cast integer-like numeric strings (e.g. '1', '10') to int keys.
 */
final class Histogram
{
    /** @var list<float> Sorted upper-bound bucket boundaries */
    private readonly array $boundaries;

    /** @var list<string> Pre-computed string keys for each boundary */
    private readonly array $boundaryKeys;

    /**
     * Per-label-key data: [buckets => [bound => count], sum => float, count => int].
     *
     * @var array<string, array{buckets: array<int|string, int>, sum: float, count: int}>
     */
    private array $series = [];

    /** @var list<float> */
    public const array DEFAULT_BOUNDARIES = [0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0, 10.0];

    /**
     * @param list<float> $boundaries
     */
    public function __construct(
        public readonly string $name,
        public readonly string $help = '',
        array $boundaries = self::DEFAULT_BOUNDARIES,
    ) {
        $sorted = $boundaries;
        sort($sorted, SORT_NUMERIC);
        $this->boundaries = $sorted;

        $keys = [];

        foreach ($sorted as $b) {
            $keys[] = (string) $b;
        }

        $this->boundaryKeys = $keys;
    }

    /**
     * Record an observed value.
     */
    public function observe(float $value, LabelSet $labels = new LabelSet()): void
    {
        $key = $labels->key();

        if (!isset($this->series[$key])) {
            $this->series[$key] = $this->emptySeries();
        }

        $this->series[$key]['sum'] += $value;
        ++$this->series[$key]['count'];

        foreach ($this->boundaries as $i => $bound) {
            if ($value <= $bound) {
                $boundKey = $this->boundaryKeys[$i];
                ++$this->series[$key]['buckets'][$boundKey];
            }
        }
    }

    /**
     * Get sum of observed values for a label set.
     */
    public function sum(LabelSet $labels = new LabelSet()): float
    {
        return $this->series[$labels->key()]['sum'] ?? 0.0;
    }

    /**
     * Get count of observations for a label set.
     */
    public function count(LabelSet $labels = new LabelSet()): int
    {
        return $this->series[$labels->key()]['count'] ?? 0;
    }

    /**
     * Get bucket counts for a label set.
     *
     * Keys are the string representation of boundary floats. PHP may auto-cast
     * integer-like numeric strings to int keys (e.g. '1' → 1, '10' → 10).
     *
     * @return array<int|string, int> bucket-boundary => cumulative count
     */
    public function buckets(LabelSet $labels = new LabelSet()): array
    {
        if (!isset($this->series[$labels->key()])) {
            return $this->emptySeries()['buckets'];
        }

        return $this->series[$labels->key()]['buckets'];
    }

    /**
     * Get the configured bucket boundaries.
     *
     * @return list<float>
     */
    public function boundaries(): array
    {
        return $this->boundaries;
    }

    /**
     * Get all series keys.
     *
     * @return list<string>
     */
    public function seriesKeys(): array
    {
        return array_keys($this->series);
    }

    /**
     * Get the total number of distinct label sets observed.
     */
    public function seriesCount(): int
    {
        return count($this->series);
    }

    /**
     * Reset all observed data.
     */
    public function reset(): void
    {
        $this->series = [];
    }

    /**
     * @return array{buckets: array<int|string, int>, sum: float, count: int}
     */
    private function emptySeries(): array
    {
        /** @var array<int|string, int> $buckets */
        $buckets = [];

        foreach ($this->boundaryKeys as $key) {
            $buckets[$key] = 0;
        }

        return [
            'buckets' => $buckets,
            'sum' => 0.0,
            'count' => 0,
        ];
    }
}
