<?php

declare(strict_types=1);

namespace Pulsar\Http\Middleware;

use Pulsar\Http\Request;
use Pulsar\Http\Response;

/**
 * HTTP middleware contract.
 *
 * Middleware can inspect/modify requests before they reach handlers,
 * and inspect/modify responses after handlers complete.
 */
interface MiddlewareInterface
{
    /**
     * Process the request and return a response.
     *
     * @param Request $request The incoming request
     * @param callable(Request): Response $next The next handler in the pipeline
     * @return Response The response
     */
    public function process(Request $request, callable $next): Response;
}
