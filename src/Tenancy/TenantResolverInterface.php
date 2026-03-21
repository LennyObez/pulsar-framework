<?php

declare(strict_types=1);

namespace Pulsar\Tenancy;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Api;

/**
 * Interface for resolving the current tenant from an HTTP request.
 * @api
 */
#[Api(since: '1.0.0')]
interface TenantResolverInterface
{
    /**
     * Attempt to resolve a tenant from the request.
     *
     * Returns null if no tenant can be determined.
     */
    public function resolve(ServerRequestInterface $request): ?Tenant;
}
