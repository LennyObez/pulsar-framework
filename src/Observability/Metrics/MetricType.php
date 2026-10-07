<?php

declare(strict_types=1);

namespace Pulsar\Observability\Metrics;

use Pulsar\Api\Api;

/**
 * Metric instrument types supported by the metrics system.
 * @api
 */
#[Api(since: '1.0.0')]
enum MetricType: string
{
    case Counter = 'counter';
    case Gauge = 'gauge';
    case Histogram = 'histogram';
}
