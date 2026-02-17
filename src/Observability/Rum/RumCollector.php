<?php

declare(strict_types=1);

namespace Pulsar\Observability\Rum;

use Pulsar\Api\Api;
use Pulsar\Observability\Metrics\LabelSet;
use Pulsar\Observability\Metrics\MetricRegistry;

use function array_slice;
use function in_array;
use function is_array;
use function is_numeric;
use function is_string;

/**
 * Collects Real User Monitoring (RUM) metrics from the frontend.
 *
 * Receives batched metrics from the rum.js client script and records
 * them into the MetricRegistry for observability dashboards.
 *
 * Accepted metric names: lcp, fid, cls, page_load, dom_content_loaded,
 * ttfb, js_error, unhandled_rejection.
 */
#[Api(since: '1.0.0')]
final readonly class RumCollector
{
    /** Maximum metrics per batch to prevent abuse. */
    private const int MAX_BATCH_SIZE = 100;

    /** Allowed metric names to prevent arbitrary metric creation. */
    private const array ALLOWED_METRICS = [
        'lcp',
        'fid',
        'cls',
        'page_load',
        'dom_content_loaded',
        'ttfb',
        'js_error',
        'unhandled_rejection',
    ];

    public function __construct(
        private MetricRegistry $metrics,
    ) {}

    /**
     * Process a batch of RUM metrics.
     *
     * @param array<string, mixed> $payload The decoded JSON payload from the client
     *
     * @return RumCollectionResult Summary of processed and rejected metrics
     */
    public function collect(array $payload): RumCollectionResult
    {
        if (!isset($payload['metrics']) || !is_array($payload['metrics'])) {
            return new RumCollectionResult(0, 0);
        }

        /** @var list<array<string, mixed>> $metrics */
        $metrics = array_slice($payload['metrics'], 0, self::MAX_BATCH_SIZE);

        $accepted = 0;
        $rejected = 0;

        foreach ($metrics as $metric) {
            if ($this->processMetric($metric)) {
                $accepted++;
            } else {
                $rejected++;
            }
        }

        return new RumCollectionResult($accepted, $rejected);
    }

    /**
     * Process a single RUM metric entry.
     *
     * @param array<string, mixed> $metric
     */
    private function processMetric(array $metric): bool
    {
        if (!isset($metric['name']) || !is_string($metric['name'])) {
            return false;
        }

        if (!in_array($metric['name'], self::ALLOWED_METRICS, true)) {
            return false;
        }

        if (!isset($metric['value']) || !is_numeric($metric['value'])) {
            return false;
        }

        $name = 'rum_' . $metric['name'];
        $value = (float) $metric['value'];

        /** @var string $url */
        $url = isset($metric['url']) && is_string($metric['url'])
            ? substr($metric['url'], 0, 200)
            : '/';

        $labels = new LabelSet(['url' => $url]);

        if ($metric['name'] === 'js_error' || $metric['name'] === 'unhandled_rejection') {
            $this->metrics->counter($name, 'RUM ' . $metric['name'] . ' count')
                ->increment($labels);
        } else {
            $buckets = match ($metric['name']) {
                'lcp' => [100.0, 250.0, 500.0, 1000.0, 2500.0, 5000.0, 10000.0],
                'fid' => [10.0, 25.0, 50.0, 100.0, 300.0],
                'cls' => [0.01, 0.05, 0.1, 0.25, 0.5, 1.0],
                'ttfb' => [50.0, 100.0, 200.0, 500.0, 1000.0, 2000.0],
                default => [100.0, 250.0, 500.0, 1000.0, 2500.0, 5000.0],
            };

            $this->metrics->histogram($name, 'RUM ' . $metric['name'], $buckets)
                ->observe($value, $labels);
        }

        return true;
    }
}
