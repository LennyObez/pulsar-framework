<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Features\Tenancy;

use Pulsar\Api\Internal;
use Pulsar\Extension\Orm\Config\OrmConfig;
use Pulsar\Extension\Orm\Domain\EntityMetadata;

/**
 * Resolves the tenant column name for a given entity.
 */
#[Internal]
final class TenantColumnResolver
{
    public function __construct(
        private readonly OrmConfig $config,
    ) {}

    /**
     * Get the tenant column name for the given entity metadata.
     *
     * Returns null if the entity is not tenant-scoped.
     */
    public function resolve(EntityMetadata $metadata): ?string
    {
        if (!$metadata->isTenantScoped) {
            return null;
        }

        return $metadata->tenantColumn ?? $this->config->tenantColumn;
    }
}
