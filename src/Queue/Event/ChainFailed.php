<?php

declare(strict_types=1);

namespace Pulsar\Queue\Event;

use Pulsar\Api\Api;

/**
 * Emitted when a chain is broken due to a job failure.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ChainFailed
{
    public function __construct(
        public string $chainId,
        public int $failedAtIndex,
        public string $failedJobId,
        public string $failedJobClass,
        public int $totalJobs,
        public int $occurredAt,
    ) {}
}
