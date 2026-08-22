<?php

declare(strict_types=1);

namespace Pulsar\Extension\Observability\Config;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Configuration for file-based JSON Lines export targets.
 *
 * Controls where observability data is written when using the
 * local file-based exporters (spans, metrics, errors).
 * @api
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
     * @param array{
     *     enabled?: bool,
     *     spans_path?: string,
     *     metrics_path?: string,
     *     errors_path?: string,
     *     flush_threshold?: int,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            enabled: $data['enabled'] ?? true,
            spansPath: $data['spans_path'] ?? 'var/observability/spans.jsonl',
            metricsPath: $data['metrics_path'] ?? 'var/observability/metrics.jsonl',
            errorsPath: $data['errors_path'] ?? 'var/observability/errors.jsonl',
            flushThreshold: $data['flush_threshold'] ?? 10,
        );
    }
}
