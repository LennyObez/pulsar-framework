<?php

declare(strict_types=1);

namespace Pulsar\Queue\Monitor;

use Pulsar\Api\Api;
use Pulsar\Observability\Metrics\Counter;
use Pulsar\Observability\Metrics\Histogram;
use Pulsar\Observability\Metrics\LabelSet;
use Pulsar\Observability\Metrics\MetricRegistry;
use Pulsar\Queue\QueueDriverInterface;

use function array_sum;
use function count;
use function floor;
use function max;
use function sort;

use const SORT_NUMERIC;

/**
 * Collects per-queue job processing metrics.
 *
 * Integrates with the framework's {@see MetricRegistry} for counter/histogram
 * metrics while maintaining raw timing samples internally for percentile
 * calculations.
 * @api
 */
#[Api(since: '1.0.0')]
final class MetricsCollector
{
    private readonly Counter $processedCounter;
    private readonly Counter $failedCounter;
    private readonly Counter $dispatchedCounter;
    private readonly Histogram $durationHistogram;

    /** @var array<string, list<float>> Raw timing samples per queue */
    private array $timingSamples = [];

    public function __construct(
        private readonly QueueDriverInterface $driver,
        ?MetricRegistry $registry = null,
    ) {
        $registry ??= new MetricRegistry();

        $this->processedCounter = $registry->counter(
            'queue_jobs_processed_total',
            'Total number of successfully processed jobs',
        );
        $this->failedCounter = $registry->counter(
            'queue_jobs_failed_total',
            'Total number of failed jobs',
        );
        $this->dispatchedCounter = $registry->counter(
            'queue_jobs_dispatched_total',
            'Total number of dispatched jobs',
        );
        $this->durationHistogram = $registry->histogram(
            'queue_job_duration_milliseconds',
            'Job processing duration in milliseconds',
            [1.0, 5.0, 10.0, 25.0, 50.0, 100.0, 250.0, 500.0, 1000.0, 5000.0, 10000.0],
        );
    }

    /**
     * Record a successfully processed job and its duration.
     */
    public function recordProcessed(string $queue, float $durationMs): void
    {
        $labels = new LabelSet(['queue' => $queue]);

        $this->processedCounter->increment($labels);
        $this->durationHistogram->observe($durationMs, $labels);

        $this->timingSamples[$queue][] = $durationMs;
    }

    /**
     * Record a failed job.
     */
    public function recordFailed(string $queue): void
    {
        $this->failedCounter->increment(new LabelSet(['queue' => $queue]));
    }

    /**
     * Record a dispatched job.
     */
    public function recordDispatched(string $queue): void
    {
        $this->dispatchedCounter->increment(new LabelSet(['queue' => $queue]));
    }

    /**
     * Get the number of successfully processed jobs for a queue.
     */
    public function processedCount(string $queue): int
    {
        return (int) $this->processedCounter->value(new LabelSet(['queue' => $queue]));
    }

    /**
     * Get the number of failed jobs for a queue.
     */
    public function failedCount(string $queue): int
    {
        return (int) $this->failedCounter->value(new LabelSet(['queue' => $queue]));
    }

    /**
     * Get the number of pending jobs for a queue (delegates to the driver).
     */
    public function pendingCount(string $queue): int
    {
        return $this->driver->size($queue);
    }

    /**
     * Compute processing-time percentiles for a queue.
     *
     * Returns zero stats when no timing samples have been recorded.
     */
    public function processingTime(string $queue): ProcessingTimeStats
    {
        $samples = $this->timingSamples[$queue] ?? [];
        $sampleCount = count($samples);

        if ($sampleCount === 0) {
            return new ProcessingTimeStats(
                p50: 0.0,
                p95: 0.0,
                p99: 0.0,
                average: 0.0,
                sampleCount: 0,
            );
        }

        sort($samples, SORT_NUMERIC);

        $average = array_sum($samples) / (float) $sampleCount;

        return new ProcessingTimeStats(
            p50: $this->percentile($samples, 50.0),
            p95: $this->percentile($samples, 95.0),
            p99: $this->percentile($samples, 99.0),
            average: $average,
            sampleCount: $sampleCount,
        );
    }

    /**
     * Reset all collected metrics and timing samples.
     */
    public function reset(): void
    {
        $this->processedCounter->reset();
        $this->failedCounter->reset();
        $this->dispatchedCounter->reset();
        $this->durationHistogram->reset();
        $this->timingSamples = [];
    }

    /**
     * Compute the value at a given percentile from a sorted sample array.
     *
     * Uses the nearest-rank method: the percentile value is the smallest
     * value in the dataset such that the given percentage of data falls
     * at or below that value.
     *
     * @param list<float> $sorted Sorted samples (ascending).
     * @param float       $pct    Percentile (0-100).
     */
    private function percentile(array $sorted, float $pct): float
    {
        $count = count($sorted);
        $rank = (int) max(0.0, floor($pct / 100.0 * (float) $count) - 1.0);

        return $sorted[$rank];
    }
}
