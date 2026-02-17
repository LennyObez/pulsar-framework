<?php

declare(strict_types=1);

namespace Pulsar\Core;

use Pulsar\Api\Api;

/**
 * Defines kernel lifecycle event names.
 *
 * These events are dispatched at key points during kernel operation.
 * Extensions and middleware can listen for these events to perform
 * work at specific lifecycle stages.
 */
#[Api(since: '1.0.0')]
enum KernelEvents: string
{
    /**
     * Dispatched after the response has been sent to the client.
     *
     * This event fires post-response and is critical for persistent runtimes
     * (RoadRunner, FrankenPHP) where cleanup must occur between requests.
     * Listeners should perform non-critical tasks: logging, metric flushing,
     * session writes, cache warming, and resource cleanup.
     *
     * The event payload is a TerminateEvent containing the request and response.
     */
    case TERMINATE = 'kernel.terminate';

    /**
     * Dispatched when the kernel begins booting.
     */
    case BOOT = 'kernel.boot';

    /**
     * Dispatched when the kernel shuts down.
     */
    case SHUTDOWN = 'kernel.shutdown';
}
