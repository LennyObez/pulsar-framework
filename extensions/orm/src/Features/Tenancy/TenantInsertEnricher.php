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
final class TenantInsertEnricher
{
    public function __construct(
        private readonly TenantScopeInterface $tenantScope,
        private readonly TenantColumnResolver $columnResolver,
    ) {}

    /**
     * Add the tenant column to the insert values if applicable.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public function enrich(array $values, EntityMetadata $metadata): array
    {
        if (!$metadata->isTenantScoped || $metadata->isTenantShared) {
            return $values;
        }

        if (!$this->tenantScope->isActive()) {
            return $values;
        }

        $column = $this->columnResolver->resolve($metadata);
        if ($column === null) {
            return $values;
        }

        $tenantId = $this->tenantScope->currentTenantId();
        if ($tenantId === null) {
            return $values;
        }

        // Only set if not already present
        if (!isset($values[$column])) {
            $values[$column] = $tenantId;
        }

        return $values;
    }
}
