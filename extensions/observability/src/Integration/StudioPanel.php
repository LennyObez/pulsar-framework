<?php

declare(strict_types=1);

namespace Pulsar\Extension\Observability\Integration;

use Pulsar\Api\Api;
use Pulsar\Extension\Observability\Config\ObservabilityConfig;
use Pulsar\Extension\Observability\Export\Otlp\SpanBatchExporter;

/**
 * Studio integration panel for observability status.
 *
 * Provides runtime status information about OTLP and JSON Lines
 * export pipelines for display in Pulsar Studio.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class StudioPanel
{
    public function __construct(
        private ObservabilityConfig $config,
        private ?SpanBatchExporter $spanExporter = null,
    ) {}

    /**
     * Get a summary of the observability pipeline status.
     *
     * @return array<string, mixed>
     */
    public function status(): array
    {
        return [
            'otlp_enabled' => $this->config->enabled,
            'otlp_endpoint' => $this->config->enabled ? $this->config->endpoint : null,
            'otlp_protocol' => $this->config->enabled ? $this->config->protocol->value : null,
            'traces_enabled' => $this->config->traces->enabled,
            'metrics_enabled' => $this->config->metrics->enabled,
            'logs_enabled' => $this->config->logs->enabled,
            'jsonlines_enabled' => $this->config->export->enabled,
            'span_queue_size' => $this->spanExporter?->queueSize() ?? 0,
            'sampler_type' => $this->config->sampler->type->value,
            'dual_export' => $this->config->dualExport,
        ];
    }
}
