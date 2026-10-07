<?php

declare(strict_types=1);

namespace Pulsar\Http\Attribute;

use Attribute;
use Pulsar\Api\Api;

/**
 * Marks a controller method or class as serving sensitive data that must
 * not be cached by browsers or intermediate proxies.
 *
 * The declaration is inert on its own: it is enforced by
 * {@see \Pulsar\Http\Middleware\NoCacheMiddleware}, and only on the routes that
 * middleware runs for. Attach it to the route (`new Route(..., middleware:
 * ['no-cache'])`) or
 * register it once in the {@see \Pulsar\Http\Middleware\PostRoutingPipeline} to
 * cover the whole application. Where it runs, a handler carrying this attribute
 * — on the method or on its declaring class — answers with:
 *   Cache-Control: no-store, no-cache, must-revalidate
 *   Pragma: no-cache
 *   Expires: 0
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
#[Api(since: '1.0.0')]
final readonly class NoCacheResponse
{
    public function __construct() {}
}
