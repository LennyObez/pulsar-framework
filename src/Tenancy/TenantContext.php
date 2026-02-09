<?php

declare(strict_types=1);

namespace Pulsar\Tenancy;

use NoDiscard;
use Override;
use Pulsar\Api\Api;
use Pulsar\Runtime\ResettableInterface;
use Pulsar\Tenancy\Exception\TenancyException;

/**
 * Holds the current tenant for the active request.
 */
#[Api]
final class TenantContext implements ResettableInterface
{
    private ?Tenant $tenant = null;

    /**
     * Set the current tenant.
     */
    public function set(Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    /**
     * Get the current tenant.
     *
     * @throws TenancyException If no tenant has been resolved.
     */
    #[NoDiscard]
    public function get(): Tenant
    {
        return $this->tenant ?? throw TenancyException::tenantNotResolved();
    }

    /**
     * Get the current tenant without throwing.
     */
    public function tryGet(): ?Tenant
    {
        return $this->tenant;
    }

    /**
     * Check if a tenant has been resolved.
     */
    public function isResolved(): bool
    {
        return $this->tenant !== null;
    }

    /**
     * Clear the current tenant.
     */
    public function clear(): void
    {
        $this->tenant = null;
    }

    #[Override]
    public function resetRequestState(): void
    {
        $this->clear();
    }
}
