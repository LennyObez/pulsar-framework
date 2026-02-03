<?php

declare(strict_types=1);

namespace Pulsar\Scheduler;

use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Pulsar\Api\Api;
use Pulsar\Observability\Metrics\MetricRegistry;

/**
 * Context passed to a job during execution.
 */
#[Api]
readonly class JobContext
{
    public function __construct(
        public DateTimeImmutable $scheduledAt,
        public DateTimeImmutable $startedAt,
        public ?LoggerInterface $logger = null,
        public ?MetricRegistry $metrics = null,
    ) {}
}
