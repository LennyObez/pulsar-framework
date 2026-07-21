<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Features\Tenancy;

use Pulsar\Api\Internal;
use Pulsar\Extension\Orm\Contracts\TenantScopeInterface;
use Pulsar\Extension\Orm\Domain\EntityMetadata;

/**
 * Enriches INSERT data with the current tenant ID.
 */
#[Internal]
final readonly class TenantInsertEnricher
{
    public function __construct(
        private TenantScopeInterface $tenantScope,
        private TenantColumnResolver $columnResolver,
    ) {}

    /**
     * Add the tenant column to the insert values if applicable.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public function enrich(array $values, EntityMetadata $metadata): array
    {
        $predicate = $this->activeTenantColumn($metadata);

        if ($predicate === null) {
            return $values;
        }

        [$column, $tenantId] = $predicate;

        // Only set if not already present
        if (!isset($values[$column])) {
            $values[$column] = $tenantId;
        }

        return $values;
    }

    /**
     * The tenant column and current tenant id that constrain data to the active
     * tenant, or null when the entity is not tenant-scoped or no tenant context
     * is active.
     *
     * Shared by INSERT enrichment and the UPDATE/DELETE tenant predicate
     * (AuditingPersister) so no write can silently cross a tenant boundary.
     *
     * @return array{0: string, 1: string}|null
     */
    public function activeTenantColumn(EntityMetadata $metadata): ?array
    {
        if (!$metadata->isTenantScoped || $metadata->isTenantShared) {
            return null;
        }

        if (!$this->tenantScope->isActive()) {
            return null;
        }

        $column = $this->columnResolver->resolve($metadata);
        if ($column === null) {
            return null;
        }

        $tenantId = $this->tenantScope->currentTenantId();
        if ($tenantId === null) {
            return null;
        }

        return [$column, $tenantId];
    }
}
