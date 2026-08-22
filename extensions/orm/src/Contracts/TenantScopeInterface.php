<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Contracts;

use Pulsar\Api\Api;

/**
 * Provides the current tenant ID for tenant-scoped queries.
 * @api
 */
#[Api(since: '1.0.0')]
interface TenantScopeInterface
{
    /**
     * Get the current tenant ID.
     *
     * Returns null when no tenant context is active (e.g., system operations).
     */
    public function currentTenantId(): ?string;

    /**
     * Check if tenant scoping is currently active.
     */
    public function isActive(): bool;
}
