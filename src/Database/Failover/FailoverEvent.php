<?php

declare(strict_types=1);

namespace Pulsar\Database\Failover;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Compliance-grade record of a failover event.
 *
 * Captures all information required for audit trails in regulated
 * environments, including correlation IDs for distributed tracing.
 */
#[Api(since: '1.0.0')]
final readonly class FailoverEvent
{
    public function __construct(
        public string $reason,
        public string $sourceEndpoint,
        public string $targetEndpoint,
        public int $affectedOperationCount,
        public float $durationMs,
        public string $correlationId,
        public DateTimeImmutable $occurredAt,
    ) {}
}
