<?php

declare(strict_types=1);

namespace Pulsar\Tests\Benchmark\Support;

use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Http\Request;
use Pulsar\Http\Response;

final class PassThroughMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $next): Response
    {
        return $next($request);
    }
}
