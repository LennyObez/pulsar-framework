<?php

declare(strict_types=1);

namespace Pulsar\Studio;

use Closure;
use Pulsar\Api\Internal;

/**
 * RAII guard for correlation context scopes.
 *
 * close() MUST be called from the same Fiber (or root) that called enter().
 * All usage follows try/finally in the same function, so this is guaranteed.
 * Cross-Fiber close is not supported.
 */
#[Internal]
final class ContextScope
{
    private bool $closed = false;

    /** @param Closure(): void $onClose */
    public function __construct(
        private readonly Closure $onClose,
    ) {}

    /**
     * Pop the associated context from the scope stack. Idempotent.
     */
    public function close(): void
    {
        if (!$this->closed) {
            $this->closed = true;
            ($this->onClose)();
        }
    }
}
