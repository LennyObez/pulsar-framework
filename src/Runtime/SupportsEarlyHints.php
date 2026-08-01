<?php

declare(strict_types=1);

namespace Pulsar\Runtime;

use Pulsar\Api\Api;

/**
 * A runtime that can emit an HTTP 103 Early Hints informational response.
 *
 * Kept separate from {@see RuntimeInterface} rather than added to it: 103 is not
 * something every runtime can do — it needs the server to keep the connection open
 * and flush an interim response — and widening the base interface would break every
 * existing implementation for a capability most of them cannot honour. A caller
 * asks with `instanceof` instead.
 *
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
interface SupportsEarlyHints
{
    /**
     * Send Link headers as a 103 interim response, before the real one.
     *
     * The client may start fetching the referenced resources while PHP is still
     * building the final response. Implementations must be a no-op when the
     * underlying server cannot deliver an interim response, never an error: early
     * hints are an optimisation, and failing a request because a hint could not be
     * sent would trade a fast page for no page.
     *
     * @param list<string> $linkHeaderValues Values for the Link header, as produced
     *                                       by {@see \Pulsar\Http\Http3\EarlyHints::toLinkHeaders()}
     */
    public function earlyHints(array $linkHeaderValues): void;
}
