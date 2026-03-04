<?php

declare(strict_types=1);

namespace Pulsar\Observability\Metrics;

use Closure;
use Pulsar\Api\Api;

use function microtime;

/**
 * Evaluates metric values against configured alert thresholds.
 *
 * Tracks how long a threshold condition has been satisfied. When the
 * condition persists for the configured `forSeconds` duration, the alert
 * fires and the registered callback is invoked.
 *
 * Designed to be called periodically (e.g., every 10 seconds from a
 * scheduler or health check loop).
 */
#[Api(since: '1.0.0')]
final class ThresholdEvaluator
{
    /** @var list<AlertThreshold> */
    private array $thresholds = [];

    /** @var array<string, float> Metric name => timestamp when condition first became true */
    private array $conditionStartTimes = [];

    /** @var list<AlertFiring> */
    private array $firings = [];

    /** @var (Closure(AlertFiring): void)|null */
    private ?Closure $onFire = null;

    public function __construct(
        private readonly MetricRegistry $registry,
    ) {}

    /**
     * Register an alert threshold.
     */
    public function addThreshold(AlertThreshold $threshold): void
    {
        $this->thresholds[] = $threshold;
    }

    /**
     * Set a callback invoked when any threshold fires.
     *
     * @param Closure(AlertFiring): void $callback
     */
    public function onFire(Closure $callback): void
    {
        $this->onFire = $callback;
    }

    /**
     * Evaluate all thresholds against current metric values.
     *
     * @return list<AlertFiring> Alerts that fired during this evaluation
     */
    public function evaluate(): array
    {
        $now = microtime(true);
        $fired = [];

        foreach ($this->thresholds as $threshold) {
            $metricValue = $this->resolveMetricValue($threshold->metricName);

            if ($metricValue === null) {
                unset($this->conditionStartTimes[$threshold->metricName]);
                continue;
            }

            $key = $threshold->metricName . ':' . $threshold->comparator . ':' . $threshold->value;

            if ($threshold->isSatisfiedBy($metricValue)) {
                if (!isset($this->conditionStartTimes[$key])) {
                    $this->conditionStartTimes[$key] = $now;
                }

                $elapsed = $now - $this->conditionStartTimes[$key];

                if ($elapsed >= (float) $threshold->forSeconds) {
                    $firing = AlertFiring::from($threshold, $metricValue);
                    $this->firings[] = $firing;
                    $fired[] = $firing;

                    ($this->onFire)?->__invoke($firing);

                    // Reset to prevent duplicate firings
                    unset($this->conditionStartTimes[$key]);
                }
            } else {
                unset($this->conditionStartTimes[$key]);
            }
        }

        return $fired;
    }

    /**
     * Get all historical firings.
     *
     * @return list<AlertFiring>
     */
    public function firings(): array
    {
        return $this->firings;
    }

    /**
     * Get the registered thresholds.
     *
     * @return list<AlertThreshold>
     */
    public function thresholds(): array
    {
        return $this->thresholds;
    }

    /**
     * Reset all state (condition timers and firing history).
     */
    public function reset(): void
    {
        $this->conditionStartTimes = [];
        $this->firings = [];
    }

    /**
     * Resolve the default label set value for a metric.
     */
    private function resolveMetricValue(string $metricName): ?float
    {
        $metrics = $this->registry->all();

        if (!isset($metrics[$metricName])) {
            return null;
        }

        $metric = $metrics[$metricName];

        if ($metric instanceof Counter || $metric instanceof Gauge) {
            return $metric->value();
        }

        return null;
    }
}
