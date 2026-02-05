<?php

declare(strict_types=1);

namespace Pulsar\Studio;

use Pulsar\Api\Internal;

/**
 * Provides the current correlation context for the active execution scope.
 */
#[Internal]
interface CorrelationContextProviderInterface
{
    /**
     * Returns the innermost active correlation context, or null if no scope is active.
     */
    public function current(): ?CorrelationContext;
}
