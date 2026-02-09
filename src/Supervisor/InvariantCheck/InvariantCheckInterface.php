<?php

declare(strict_types=1);

namespace Pulsar\Supervisor\InvariantCheck;

use Pulsar\Api\Api;

/**
 * Contract for runtime invariant checks.
 *
 * Invariant checks run periodically while the supervisor is active to
 * verify that system invariants hold (e.g., database connectivity,
 * expected filesystem state, configuration consistency).
 */
#[Api(since: '1.0.0')]
interface InvariantCheckInterface
{
    /**
     * Human-readable name of this invariant check.
     */
    public function getName(): string;

    /**
     * Execute the invariant check and return the result.
     */
    public function check(): InvariantCheckResult;
}
