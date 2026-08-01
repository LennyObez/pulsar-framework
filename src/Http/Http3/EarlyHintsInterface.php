<?php

declare(strict_types=1);

namespace Pulsar\Http\Http3;

use Pulsar\Api\Api;

/**
 * A set of early hints that knows how to emit itself.
 *
 * {@see EarlyHints} is the framework's implementation and stays final — it is a
 * builder over a list of Link values, and there is nothing in it worth overriding.
 * The seam is here instead, so that an application whose hints depend on the request
 * (a different shell per section, say) can supply its own emitter without
 * reimplementing the middleware, and so the middleware's decision to emit is
 * observable in a test rather than taken on trust.
 *
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
interface EarlyHintsInterface
{
    /**
     * Emit the hints, as a 103 interim response where the SAPI can do that.
     *
     * Must be called before any output and before the final response headers.
     * Implementations must never fail a request because a hint could not be sent:
     * early hints are an optimisation, and trading a fast page for no page is a
     * bad bargain at any hit rate.
     */
    public function send(): void;

    /**
     * Whether there is anything worth emitting.
     */
    public function isEmpty(): bool;
}
