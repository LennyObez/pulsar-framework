<?php

declare(strict_types=1);

namespace Pulsar\Context;

use NoDiscard;
use Override;
use Pulsar\Api\Api;
use Pulsar\Context\Exception\ContextException;
use Pulsar\Runtime\ResettableInterface;

/**
 * Mutable holder for the current request context.
 *
 * Follows the same pattern as TenantContext: set/get/tryGet/clear with
 * ResettableInterface for per-request cleanup in the persistent runtime.
 */
#[Api(since: '1.0.0')]
final class RequestContextHolder implements ResettableInterface
{
    private ?RequestContext $context = null;

    /**
     * Set the current request context.
     */
    public function set(RequestContext $context): void
    {
        $this->context = $context;
    }

    /**
     * Get the current request context.
     *
     * @throws ContextException If no context has been set.
     */
    #[NoDiscard]
    public function get(): RequestContext
    {
        return $this->context ?? throw ContextException::notAvailable();
    }

    /**
     * Get the current request context without throwing.
     */
    public function tryGet(): ?RequestContext
    {
        return $this->context;
    }

    /**
     * Check if a request context is available.
     */
    public function isAvailable(): bool
    {
        return $this->context !== null;
    }

    /**
     * Clear the current request context.
     */
    public function clear(): void
    {
        $this->context = null;
    }

    #[Override]
    public function resetRequestState(): void
    {
        $this->clear();
    }
}
