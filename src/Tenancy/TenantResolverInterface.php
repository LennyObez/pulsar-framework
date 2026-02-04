<?php

declare(strict_types=1);

namespace Pulsar\Tenancy;

use Pulsar\Http\Request;

/**
 * Interface for resolving the current tenant from an HTTP request.
 */
interface TenantResolverInterface
{
    /**
     * Attempt to resolve a tenant from the request.
     *
     * Returns null if no tenant can be determined.
     */
    public function resolve(Request $request): ?Tenant;
}
