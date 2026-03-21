<?php

declare(strict_types=1);

namespace Pulsar\Scheduler;

use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Pulsar\Api\Api;
use Pulsar\Context\RequestContext;
use Pulsar\Observability\Metrics\MetricRegistry;

/**
 * Context passed to a job during execution.
 */
#[Api(since: '1.0.0')]
final readonly class JobContext
{
    public function __construct(
        public DateTimeImmutable $scheduledAt,
        public DateTimeImmutable $startedAt,
        public ?LoggerInterface $logger = null,
        public ?MetricRegistry $metrics = null,
        public ?RequestContext $requestContext = null,
    ) {}
}
