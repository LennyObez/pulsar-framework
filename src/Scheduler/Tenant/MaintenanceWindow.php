<?php

declare(strict_types=1);

namespace Pulsar\Scheduler\Tenant;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Defines a time window during which a tenant's scheduled jobs are suspended.
 */
#[Api(since: '1.0.0')]
readonly class MaintenanceWindow
{
    public function __construct(
        public DateTimeImmutable $start,
        public DateTimeImmutable $end,
        public string $reason,
    ) {}

    /**
     * Check whether the maintenance window is active at the given time.
     */
    public function isActive(DateTimeImmutable $at): bool
    {
        return $at >= $this->start && $at <= $this->end;
    }
}
