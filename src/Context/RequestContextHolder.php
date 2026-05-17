<?php

declare(strict_types=1);

namespace Pulsar\Context;

use Fiber;
use NoDiscard;
use Override;
use Pulsar\Api\Api;
use Pulsar\Context\Exception\ContextException;
use Pulsar\Runtime\ResettableInterface;
use stdClass;
use WeakMap;

/**
 * Mutable holder for the current request context, isolated per Fiber.
 *
 * Persistent-runtime workers (RoadRunner, FrankenPHP, Swoole) interleave
 * Fiber-suspended HTTP requests on the same worker process. A naive
 * `private ?RequestContext $context` field was shared across every Fiber on
 * the worker, so request A's correlation/causation IDs would leak into
 * request B's audit trail and vice-versa (F25.2).
 *
 * Storage is keyed by `Fiber::getCurrent()` — or a stable `$rootKey` for
 * code running outside any Fiber — using a `WeakMap`. When a Fiber
 * completes and is garbage-collected its slot is reclaimed automatically.
 * @api
 */
#[Api(since: '1.0.0')]
final class RequestContextHolder implements ResettableInterface
{
    /** @var WeakMap<object, RequestContext> Per-Fiber (or root) request context. */
    private WeakMap $contexts;

    private readonly stdClass $rootKey;

    public function __construct()
    {
        /** @var WeakMap<object, RequestContext> $map */
        $map = new WeakMap();
        $this->contexts = $map;
        $this->rootKey = new stdClass();
    }

    /**
     * Set the current Fiber's request context.
     */
    public function set(RequestContext $context): void
    {
        $this->contexts[$this->currentKey()] = $context;
    }

    /**
     * Get the current Fiber's request context.
     *
     * @throws ContextException If no context has been set.
     */
    #[NoDiscard]
    public function get(): RequestContext
    {
        return $this->tryGet() ?? throw ContextException::notAvailable();
    }

    /**
     * Get the current Fiber's request context without throwing.
     */
    public function tryGet(): ?RequestContext
    {
        return $this->contexts[$this->currentKey()] ?? null;
    }

    /**
     * Check if a request context is available for the current Fiber.
     */
    public function isAvailable(): bool
    {
        return isset($this->contexts[$this->currentKey()]);
    }

    /**
     * Clear the current Fiber's request context.
     */
    public function clear(): void
    {
        unset($this->contexts[$this->currentKey()]);
    }

    #[Override]
    public function resetRequestState(): void
    {
        $this->clear();
    }

    private function currentKey(): object
    {
        return Fiber::getCurrent() ?? $this->rootKey;
    }
}
