<?php

declare(strict_types=1);

namespace Pulsar\Scheduler\Tenant;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Scheduler\JobInterface;
use Pulsar\Tenancy\Guard\TenantId;
use Pulsar\Tenancy\TenantProviderInterface;

/**
 * Resolves scheduled jobs and maintenance windows per tenant.
 */
#[Api(since: '1.0.0')]
final readonly class TenantScheduleResolver
{
    public function __construct(
        private TenantScheduleProviderInterface $provider,
        private TenantProviderInterface $tenantProvider,
    ) {}

    /**
     * Get the scheduled jobs for a specific tenant.
     *
     * @return list<JobInterface>
     */
    #[NoDiscard]
    public function resolve(TenantId $tenantId): array
    {
        return $this->provider->getScheduledJobs($tenantId);
    }

    /**
     * Get all tenants' scheduled jobs.
     *
     * @return array<string, list<JobInterface>>
     */
    #[NoDiscard]
    public function resolveAll(): array
    {
        $result = [];

        foreach ($this->tenantProvider->getActiveTenants() as $tenantId) {
            $result[$tenantId->toString()] = $this->provider->getScheduledJobs($tenantId);
        }

        return $result;
    }

    /**
     * Check whether a tenant is in a maintenance window at the given time.
     */
    public function isInMaintenanceWindow(TenantId $tenantId, DateTimeImmutable $at): bool
    {
        $window = $this->provider->getMaintenanceWindow($tenantId);

        if ($window === null) {
            return false;
        }

        return $window->isActive($at);
    }
}
