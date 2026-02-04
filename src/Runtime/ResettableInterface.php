<?php

declare(strict_types=1);

namespace Pulsar\Runtime;

use Pulsar\Api\Api;

/**
 * Opt-in interface for services that hold request-scoped mutable state.
 *
 * Services implementing this interface will have their state reset
 * between requests in the persistent runtime. The reset is called
 * deterministically via the RequestResetRegistry.
 */
#[Api(since: '1.0.0')]
interface ResettableInterface
{
    /**
     * Reset all request-scoped mutable state.
     *
     * Called after each request in the persistent runtime to prevent
     * cross-request state leakage.
     */
    public function resetRequestState(): void;
}
