<?php

declare(strict_types=1);

namespace Pulsar\Extension\Observability\Integration;

use Pulsar\Api\Api;
use Pulsar\Extension\Observability\Config\ObservabilityConfig;
use Pulsar\Extension\Observability\Export\Otlp\SpanBatchExporter;

/**
 * Health check endpoint for observability subsystem.
 *
 * Reports the health of OTLP and JSON Lines export pipelines,
 * including queue saturation and configuration status.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class HealthCheck
{
    private const float QUEUE_SATURATION_THRESHOLD = 0.8;

    public function __construct(
        private ObservabilityConfig $config,
        private ?SpanBatchExporter $spanExporter = null,
    ) {}

    /**
     * Check the health of the observability pipeline.
     *
     * @return array{healthy: bool, checks: array<string, array{status: string, detail: string}>}
     */
    public function check(): array
    {
        $checks = [];
        $healthy = true;

        // OTLP configuration check
        if ($this->config->enabled) {
            $checks['otlp_configured'] = [
                'status' => 'pass',
                'detail' => 'OTLP export is enabled and configured',
            ];

            // Queue saturation check
            if ($this->spanExporter !== null) {
                $queueSize = $this->spanExporter->queueSize();
                $maxQueue = $this->config->batch->maxQueueSize;
                $saturation = $maxQueue > 0 ? $queueSize / $maxQueue : 0.0;

                if ($saturation >= self::QUEUE_SATURATION_THRESHOLD) {
                    $checks['queue_saturation'] = [
                        'status' => 'warn',
                        'detail' => "Span queue is {$queueSize}/{$maxQueue} (" . (int) ($saturation * 100) . '% full)',
                    ];
                    $healthy = false;
                } else {
                    $checks['queue_saturation'] = [
                        'status' => 'pass',
                        'detail' => "Span queue is {$queueSize}/{$maxQueue}",
                    ];
                }
            }
        } else {
            $checks['otlp_configured'] = [
                'status' => 'pass',
                'detail' => 'OTLP export is disabled (no-op mode)',
            ];
        }

        // JSON Lines check
        $checks['jsonlines_configured'] = [
            'status' => 'pass',
            'detail' => $this->config->export->enabled
                ? 'JSON Lines export is enabled'
                : 'JSON Lines export is disabled',
        ];

        return [
            'healthy' => $healthy,
            'checks' => $checks,
        ];
    }
}
