<?php

declare(strict_types=1);

namespace Pulsar\Observability\Metrics;

/**
 * Metric instrument types supported by the metrics system.
 */
enum MetricType: string
{
    case Counter = 'counter';
    case Gauge = 'gauge';
    case Histogram = 'histogram';
}
