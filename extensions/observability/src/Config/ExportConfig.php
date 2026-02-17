<?php

declare(strict_types=1);

namespace Pulsar\Extension\Observability\Config;

use NoDiscard;
use Pulsar\Api\Api;

use function is_bool;
use function is_int;
use function is_string;

/**
 * Configuration for file-based JSON Lines export targets.
 *
 * Controls where observability data is written when using the
 * local file-based exporters (spans, metrics, errors).
 */
#[Api(since: '1.0.0')]
final readonly class ExportConfig
{
    public function __construct(
        public bool $enabled = true,
        public string $spansPath = 'var/observability/spans.jsonl',
        public string $metricsPath = 'var/observability/metrics.jsonl',
        public string $errorsPath = 'var/observability/errors.jsonl',
        public int $flushThreshold = 10,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $rawEnabled = $data['enabled'] ?? true;
        $rawSpansPath = $data['spans_path'] ?? 'var/observability/spans.jsonl';
        $rawMetricsPath = $data['metrics_path'] ?? 'var/observability/metrics.jsonl';
        $rawErrorsPath = $data['errors_path'] ?? 'var/observability/errors.jsonl';
        $rawFlushThreshold = $data['flush_threshold'] ?? 10;

        return new self(
            enabled: is_bool($rawEnabled) ? $rawEnabled : true,
            spansPath: is_string($rawSpansPath) ? $rawSpansPath : 'var/observability/spans.jsonl',
            metricsPath: is_string($rawMetricsPath) ? $rawMetricsPath : 'var/observability/metrics.jsonl',
            errorsPath: is_string($rawErrorsPath) ? $rawErrorsPath : 'var/observability/errors.jsonl',
            flushThreshold: is_int($rawFlushThreshold) ? $rawFlushThreshold : 10,
        );
    }
}
