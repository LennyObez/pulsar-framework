<?php

declare(strict_types=1);

namespace Pulsar\Scheduler\Tenant;

use Pulsar\Api\Api;
use Pulsar\Scheduler\JobInterface;
use Pulsar\Tenancy\Guard\TenantId;

/**
 * Contract for providing per-tenant schedule definitions.
 */
#[Api(since: '1.0.0')]
interface TenantScheduleProviderInterface
{
    /**
     * Get the scheduled jobs for a specific tenant.
     *
     * @return list<JobInterface>
     */
    public function getScheduledJobs(TenantId $tenantId): array;

    /**
     * Get the maintenance window for a specific tenant, if any.
     */
    public function getMaintenanceWindow(TenantId $tenantId): ?MaintenanceWindow;
}
