<?php

declare(strict_types=1);

namespace Pulsar\Queue\Monitor;

use Pulsar\Api\Api;

/**
 * Immutable snapshot of processing-time percentiles for a queue.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ProcessingTimeStats
{
    public function __construct(
        public float $p50,
        public float $p95,
        public float $p99,
        public float $average,
        public int $sampleCount,
    ) {}
}
