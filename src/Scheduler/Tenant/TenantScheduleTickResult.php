<?php

declare(strict_types=1);

namespace Pulsar\Scheduler\Tenant;

use Pulsar\Api\Api;

/**
 * Result of a tenant-aware scheduler tick.
 */
#[Api(since: '1.0.0')]
final readonly class TenantScheduleTickResult
{
    public function __construct(
        public int $tenantsProcessed,
        public int $jobsDispatched,
        public int $tenantsSkipped,
        public int $jobsFailed,
    ) {}
}
