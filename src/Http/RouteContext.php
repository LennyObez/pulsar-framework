<?php

declare(strict_types=1);

namespace Pulsar\Http;

use Fiber;
use Pulsar\Api\Internal;
use stdClass;
use WeakMap;

/**
 * Mutable holder for the matched route's pattern and name, isolated per Fiber.
 *
 * Populated by the Kernel after route matching (inside dispatchRoute),
 * and read by MetricsMiddleware / TracingMiddleware after $next() returns.
 * This bridges the gap between global middleware (which runs before routing)
 * and route matching (which happens inside $next).
 *
 * Persistent-runtime workers (RoadRunner, FrankenPHP, Swoole) interleave
 * Fiber-suspended HTTP requests on the same worker process. A shared
 * `public ?string $pattern` field would be overwritten by every concurrent
 * request, tagging request A's metrics with request B's route label and
 * vice-versa. Storage is therefore keyed by `Fiber::getCurrent()` via a
 * `WeakMap`, with a stable `$rootKey` for non-Fiber callers.
 *
 * Must be reset at the start of each request for long-lived worker safety.
 */
#[Internal]
final class RouteContext
{
    /**
     * @var WeakMap<object, array{pattern: ?string, name: ?string}>
     *      Per-Fiber (or root) route slots.
     */
    private WeakMap $slots;

    private readonly stdClass $rootKey;

    public function __construct()
    {
        /** @var WeakMap<object, array{pattern: ?string, name: ?string}> $map */
        $map = new WeakMap();
        $this->slots = $map;
        $this->rootKey = new stdClass();
    }

    public function setPattern(?string $pattern): void
    {
        $key = $this->currentKey();
        $slot = $this->slots[$key] ?? ['pattern' => null, 'name' => null];
        $slot['pattern'] = $pattern;
        $this->slots[$key] = $slot;
    }

    public function setName(?string $name): void
    {
        $key = $this->currentKey();
        $slot = $this->slots[$key] ?? ['pattern' => null, 'name' => null];
        $slot['name'] = $name;
        $this->slots[$key] = $slot;
    }

    public function pattern(): ?string
    {
        $slot = $this->slots[$this->currentKey()] ?? null;

        return $slot['pattern'] ?? null;
    }

    public function name(): ?string
    {
        $slot = $this->slots[$this->currentKey()] ?? null;

        return $slot['name'] ?? null;
    }

    /**
     * Return the best available label for metrics/tracing.
     *
     * Priority: route name > route pattern > 'unmatched'.
     */
    public function label(): string
    {
        $slot = $this->slots[$this->currentKey()] ?? null;

        if ($slot === null) {
            return 'unmatched';
        }

        return $slot['name'] ?? $slot['pattern'] ?? 'unmatched';
    }

    /**
     * Reset state for the next request (worker reuse safety).
     *
     * Only clears the current Fiber's slot — other Fibers' state is preserved.
     */
    public function reset(): void
    {
        unset($this->slots[$this->currentKey()]);
    }

    private function currentKey(): object
    {
        return Fiber::getCurrent() ?? $this->rootKey;
    }
}
