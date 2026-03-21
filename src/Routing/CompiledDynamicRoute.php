<?php

declare(strict_types=1);

namespace Pulsar\Routing;

use Pulsar\Api\Api;

/**
 * A dynamic (parameterized) route with its pre-compiled regex pattern.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class CompiledDynamicRoute
{
    /**
     * @param string $pattern Pre-compiled regex pattern for path matching
     * @param CompiledRouteEntry $entry The route entry data
     * @param string|null $host Host pattern string (null = match any)
     * @param string|null $hostPattern Pre-compiled regex for host matching
     */
    public function __construct(
        public string $pattern,
        public CompiledRouteEntry $entry,
        public ?string $host = null,
        public ?string $hostPattern = null,
    ) {}
}
