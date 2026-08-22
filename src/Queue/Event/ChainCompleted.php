<?php

declare(strict_types=1);

namespace Pulsar\Queue\Event;

use Pulsar\Api\Api;

/**
 * Emitted when all jobs in a chain have completed successfully.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ChainCompleted
{
    public function __construct(
        public string $chainId,
        public int $totalJobs,
        public int $occurredAt,
    ) {}
}
