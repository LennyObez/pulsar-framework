<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Export\JsonLines\Metrics;

use Pulsar\Api\Api;
use Pulsar\Observability\Metrics\MetricSnapshot;

/**
 * Contract for exporting metric snapshots to an external destination.
 */
#[Api(since: '1.0.0')]
interface MetricsExporterInterface
{
    /**
     * Export a single metric snapshot.
     */
    public function export(MetricSnapshot $snapshot): void;

    /**
     * Flush any buffered snapshots to the destination.
     */
    public function flush(): void;

    /**
     * Shut down the exporter, flushing remaining data and releasing resources.
     */
    public function shutdown(): void;
}
