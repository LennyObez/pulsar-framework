<?php

declare(strict_types=1);

namespace Pulsar\Routing;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Api;

/**
 * Resolves the domain context from an incoming HTTP request.
 *
 * Implementations extract the host from the request (respecting
 * reverse proxy headers like X-Forwarded-Host) and determine
 * which subdomain and extension scopes apply.
 * @api
 */
#[Api(since: '1.0.0')]
interface DomainResolverInterface
{
    /**
     * Resolve the domain context for the given request.
     */
    public function resolve(ServerRequestInterface $request): DomainContext;
}
