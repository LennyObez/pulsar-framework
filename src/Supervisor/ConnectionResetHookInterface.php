<?php

declare(strict_types=1);

namespace Pulsar\Supervisor;

use Pulsar\Api\Api;

/**
 * Hook for resetting long-lived connections managed by the supervisor.
 *
 * Implementations should close and re-establish database connections,
 * message broker links, or other persistent connections that may have
 * become stale or leaked.
 */
#[Api]
interface ConnectionResetHookInterface
{
    /**
     * Reset all managed connections.
     */
    public function reset(): void;
}
