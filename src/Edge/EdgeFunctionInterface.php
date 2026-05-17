<?php

declare(strict_types=1);

namespace Pulsar\Edge;

use Pulsar\Api\Api;

/**
 * Contract for edge functions that run at CDN edge locations.
 *
 * Edge functions are lightweight request processors designed
 * for low-latency operations like A/B testing, geo-routing,
 * bot filtering, and rate limiting. They execute before the
 * request reaches the origin server.
 *
 * Implementations must be serializable for deployment to edge
 * platforms (Cloudflare Workers, Vercel Edge, etc.).
 * @api
 */
#[Api(since: '1.0.0')]
interface EdgeFunctionInterface
{
    /**
     * Process the edge request.
     *
     * Return null to pass through to origin, or an EdgeResponse to short-circuit.
     */
    public function handle(EdgeRequest $request): ?EdgeResponse;

    /**
     * Unique name for this edge function.
     */
    public function name(): string;
}
