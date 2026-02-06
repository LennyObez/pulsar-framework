<?php

declare(strict_types=1);

namespace Pulsar\Supervisor;

use Pulsar\Api\Api;

/**
 * Hook for cache purge operations triggered by the supervisor.
 *
 * Implementations should clear application caches (e.g., config,
 * route, or view caches) without side effects on persistent data.
 */
#[Api]
interface CachePurgeHookInterface
{
    /**
     * Purge all relevant caches.
     */
    public function purge(): void;
}
