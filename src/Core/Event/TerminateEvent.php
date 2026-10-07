<?php

declare(strict_types=1);

namespace Pulsar\Core\Event;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Api;

/**
 * Event dispatched after the HTTP response has been sent to the client.
 *
 * Listeners use this to perform post-response work: flushing metrics,
 * writing audit logs, closing database connections, sending deferred
 * notifications, or any other non-time-critical task.
 *
 * This event is essential for persistent runtimes (RoadRunner, FrankenPHP)
 * where the process survives between requests and cleanup must be explicit.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class TerminateEvent
{
    public function __construct(
        public ServerRequestInterface $request,
        public ResponseInterface $response,
    ) {}
}
