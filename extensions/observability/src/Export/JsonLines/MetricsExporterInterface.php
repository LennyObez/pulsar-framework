<?php

declare(strict_types=1);

namespace Pulsar\Extension\Observability\Export\JsonLines;

use Pulsar\Api\Api;
use Pulsar\Observability\Metrics\MetricSnapshot;

/**
 * Contract for exporting metric snapshots to an external destination.
 */
#[Api(since: '1.0.0')]
interface MetricsExporterInterface
{
    public function export(MetricSnapshot $snapshot): void;

    public function flush(): void;

    public function shutdown(): void;
}
