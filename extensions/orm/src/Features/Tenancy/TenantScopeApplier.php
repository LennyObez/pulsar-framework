<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Features\Tenancy;

use Pulsar\Api\Internal;
use Pulsar\Extension\Orm\Contracts\TenantScopeInterface;
use Pulsar\Extension\Orm\Domain\EntityMetadata;
use Pulsar\Extension\Orm\Features\Query\SelectBuilder;

/**
 * Applies tenant scoping filters to queries.
 */
#[Internal]
final readonly class TenantScopeApplier
{
    public function __construct(
        private TenantScopeInterface $tenantScope,
        private TenantColumnResolver $columnResolver,
    ) {}

    /**
     * Apply tenant filter to a SELECT query if the entity is tenant-scoped
     * and a tenant context is active.
     */
    public function apply(SelectBuilder $builder, EntityMetadata $metadata): void
    {
        if (!$metadata->isTenantScoped || $metadata->isTenantShared) {
            return;
        }

        if (!$this->tenantScope->isActive()) {
            return;
        }

        $column = $this->columnResolver->resolve($metadata);
        if ($column === null) {
            return;
        }

        $tenantId = $this->tenantScope->currentTenantId();
        if ($tenantId === null) {
            return;
        }

        $builder->where($column, $tenantId);
    }
}
