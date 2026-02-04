<?php

declare(strict_types=1);

namespace Pulsar\Observability\Context;

use Pulsar\Api\Api;

/**
 * Provides the current correlation context for the active execution scope.
 *
 * Implementations track fiber-scoped or request-scoped correlation IDs
 * for distributed tracing and event correlation.
 */
#[Api]
interface CorrelationContextProviderInterface
{
    /**
     * Returns the innermost active correlation context, or null if no scope is active.
     */
    public function current(): ?CorrelationContext;
}
