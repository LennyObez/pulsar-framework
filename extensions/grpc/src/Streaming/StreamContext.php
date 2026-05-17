<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Streaming;

use Closure;
use Pulsar\Api\Api;

/**
 * Streaming call context with cancellation support.
 *
 * Allows service handlers to detect cancellation and register
 * cleanup callbacks for graceful teardown of streaming RPCs.
 * @api
 */
#[Api(since: '1.0.0')]
final class StreamContext
{
    public private(set) bool $cancelled = false;

    /** @var list<Closure(): void> */
    private array $cancelCallbacks = [];


    /**
     * Cancel the streaming call.
     *
     * Invokes all registered cancellation callbacks in registration order.
     */
    public function cancel(): void
    {
        if ($this->cancelled) {
            return;
        }

        $this->cancelled = true;

        foreach ($this->cancelCallbacks as $callback) {
            $callback();
        }
    }

    /**
     * Register a callback to be invoked when the call is cancelled.
     *
     * If the call is already cancelled, the callback is invoked immediately.
     *
     * @param Closure(): void $callback
     */
    public function onCancel(Closure $callback): void
    {
        if ($this->cancelled) {
            $callback();

            return;
        }

        $this->cancelCallbacks[] = $callback;
    }
}
