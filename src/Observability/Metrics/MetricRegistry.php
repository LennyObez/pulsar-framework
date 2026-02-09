<?php

declare(strict_types=1);

namespace Pulsar\Observability\Metrics;

use Pulsar\Api\Api;
use Pulsar\Observability\Metrics\Exception\MetricsException;

/**
 * Central store for all application metrics.
 *
 * Create-or-return semantics: calling counter/gauge/histogram with the same
 * name returns the existing instrument. Throws on type mismatch.
 */
#[Api(since: '1.0.0')]
final class MetricRegistry
{
    /** @var array<string, Counter|Gauge|Histogram> */
    private array $metrics = [];

    /** @var array<string, MetricType> */
    private array $types = [];

    /**
     * Get or create a counter.
     *
     * @throws MetricsException On type mismatch
     */
    public function counter(string $name, string $help = ''): Counter
    {
        if (isset($this->metrics[$name])) {
            $this->assertType($name, MetricType::Counter);

            /** @var Counter */
            return $this->metrics[$name];
        }

        $counter = new Counter($name, $help);
        $this->metrics[$name] = $counter;
        $this->types[$name] = MetricType::Counter;

        return $counter;
    }

    /**
     * Get or create a gauge.
     *
     * @throws MetricsException On type mismatch
     */
    public function gauge(string $name, string $help = ''): Gauge
    {
        if (isset($this->metrics[$name])) {
            $this->assertType($name, MetricType::Gauge);

            /** @var Gauge */
            return $this->metrics[$name];
        }

        $gauge = new Gauge($name, $help);
        $this->metrics[$name] = $gauge;
        $this->types[$name] = MetricType::Gauge;

        return $gauge;
    }

    /**
     * Get or create a histogram.
     *
     * @param list<float> $boundaries
     *
     * @throws MetricsException On type mismatch
     */
    public function histogram(string $name, string $help = '', array $boundaries = Histogram::DEFAULT_BOUNDARIES): Histogram
    {
        if (isset($this->metrics[$name])) {
            $this->assertType($name, MetricType::Histogram);

            /** @var Histogram */
            return $this->metrics[$name];
        }

        $histogram = new Histogram($name, $help, $boundaries);
        $this->metrics[$name] = $histogram;
        $this->types[$name] = MetricType::Histogram;

        return $histogram;
    }

    /**
     * Check if a metric is registered.
     */
    public function has(string $name): bool
    {
        return isset($this->metrics[$name]);
    }

    /**
     * Get the type of a registered metric.
     */
    public function typeOf(string $name): ?MetricType
    {
        return $this->types[$name] ?? null;
    }

    /**
     * Get all registered metrics.
     *
     * @return array<string, Counter|Gauge|Histogram>
     */
    public function all(): array
    {
        return $this->metrics;
    }

    /**
     * @throws MetricsException
     */
    private function assertType(string $name, MetricType $expected): void
    {
        $actual = $this->types[$name];

        if ($actual !== $expected) {
            throw MetricsException::typeMismatch($name, $actual->value, $expected->value);
        }
    }
}
