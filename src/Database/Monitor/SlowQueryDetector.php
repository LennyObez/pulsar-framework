<?php

declare(strict_types=1);

namespace Pulsar\Database\Monitor;

use Override;
use Psr\Log\LoggerInterface;
use Pulsar\Api\Api;
use Pulsar\Observability\Metrics\MetricRegistry;

/**
 * Detects queries exceeding the configured duration threshold.
 */
#[Api(since: '1.0.0')]
final readonly class SlowQueryDetector implements SlowQueryDetectorInterface
{
    public function __construct(
        private MonitorConfig $config,
        private ?LoggerInterface $logger = null,
        private ?MetricRegistry $metricRegistry = null,
    ) {}

    #[Override]
    public function check(string $sql, float $durationMs): bool
    {
        if ($durationMs < $this->config->slowQueryThresholdMs) {
            return false;
        }

        $normalizedSql = self::normalizeSql($sql);
        $classification = QueryClassifier::classify($sql);

        $this->logger?->warning('Slow query detected', [
            'sql' => $normalizedSql,
            'duration_ms' => $durationMs,
            'threshold_ms' => $this->config->slowQueryThresholdMs,
            'classification' => $classification->value,
        ]);

        $this->metricRegistry
            ?->counter('db.slow_queries_total', 'Total number of slow queries detected')
            ->increment();

        return true;
    }

    #[Override]
    public function getThresholdMs(): float
    {
        return (float) $this->config->slowQueryThresholdMs;
    }

    private static function normalizeSql(string $sql): string
    {
        if (str_contains($sql, '?') || preg_match('/:\w+/', $sql) === 1) {
            return $sql;
        }

        $result = preg_replace("/('[^']*')/", '?', $sql);
        $result = preg_replace('/\b\d+(\.\d+)?\b/', '?', $result ?? $sql);

        return $result ?? $sql;
    }
}
