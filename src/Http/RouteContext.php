<?php

declare(strict_types=1);

namespace Pulsar\Http;

use Pulsar\Api\Internal;

/**
 * Mutable holder for the matched route's pattern and name.
 *
 * Populated by the Kernel after route matching (inside dispatchRoute),
 * and read by MetricsMiddleware / TracingMiddleware after $next() returns.
 * This bridges the gap between global middleware (which runs before routing)
 * and route matching (which happens inside $next).
 *
 * Must be reset at the start of each request for long-lived worker safety.
 */
#[Internal]
final class RouteContext
{
    public ?string $pattern = null;
    public ?string $name = null;

    /**
     * Return the best available label for metrics/tracing.
     *
     * Priority: route name > route pattern > 'unmatched'.
     */
    public function label(): string
    {
        return $this->name ?? $this->pattern ?? 'unmatched';
    }

    /**
     * Reset state for the next request (worker reuse safety).
     */
    public function reset(): void
    {
        $this->pattern = null;
        $this->name = null;
    }
}
