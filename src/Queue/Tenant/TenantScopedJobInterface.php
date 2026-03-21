<?php

declare(strict_types=1);

namespace Pulsar\Queue\Tenant;

use Pulsar\Api\Api;
use Pulsar\Queue\QueueableInterface;

/**
 * Marker interface for jobs that must run within a tenant's scope.
 *
 * Jobs implementing this interface signal to the worker/middleware pipeline
 * that they require tenant context restoration from the envelope's tenantId.
 * The actual context restoration is handled by TenantJobMiddleware.
 * @api
 */
#[Api(since: '1.0.0')]
interface TenantScopedJobInterface extends QueueableInterface
{
    /**
     * The tenant ID this job is scoped to.
     */
    public function getTenantId(): string;
}
