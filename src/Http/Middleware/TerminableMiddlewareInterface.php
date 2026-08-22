<?php

declare(strict_types=1);

namespace Pulsar\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Pulsar\Api\Api;

/**
 * Middleware that performs work after the response has been sent.
 *
 * Extends PSR-15 MiddlewareInterface with a terminate() callback
 * that runs post-response. This is critical for persistent runtimes
 * where the process does not exit between requests.
 *
 * Common uses: flushing metrics/logs, closing connections, updating
 * caches, sending async notifications, and recording analytics.
 * @api
 */
#[Api(since: '1.0.0')]
interface TerminableMiddlewareInterface extends MiddlewareInterface
{
    /**
     * Perform post-response work.
     *
     * Called by the kernel after the response has been sent to the client.
     * Implementations must not write to the response: it has already been emitted.
     *
     * @param ServerRequestInterface $request  The original request
     * @param ResponseInterface      $response The response that was sent
     */
    public function terminate(ServerRequestInterface $request, ResponseInterface $response): void;
}
