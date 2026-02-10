<?php

declare(strict_types=1);

namespace Pulsar\Database\Failover;

use Pulsar\Api\Api;

/**
 * Strategy for resolving a failover target endpoint.
 */
#[Api(since: '1.0.0')]
interface FailoverStrategyInterface
{
    /**
     * Resolve the target endpoint for failover, or null if none available.
     */
    public function resolveTarget(): ?string;

    /**
     * Get the strategy name for logging and diagnostics.
     */
    public function name(): string;
}
