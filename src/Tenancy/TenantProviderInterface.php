<?php

declare(strict_types=1);

namespace Pulsar\Tenancy;

use Pulsar\Api\Api;
use Pulsar\Tenancy\Guard\TenantId;

/**
 * Contract for retrieving the list of active tenants.
 * @api
 */
#[Api(since: '1.0.0')]
interface TenantProviderInterface
{
    /**
     * @return list<TenantId>
     */
    public function getActiveTenants(): array;
}
