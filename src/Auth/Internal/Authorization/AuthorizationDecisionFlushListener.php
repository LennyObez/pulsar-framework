<?php

declare(strict_types=1);

namespace Pulsar\Auth\Internal\Authorization;

use Pulsar\Api\Internal;

/**
 * Empties {@see BufferedAuthorizationDecisionSink} at a drain point.
 *
 * A drain point is an event that is, by construction, outside every
 * authorization decision: the kernel's `TerminateEvent`, dispatched after the
 * response has gone out, and the queue's `JobCompleted` / `JobFailed`,
 * dispatched between jobs. `AuthWiring` registers this one listener on all
 * three. None of them carries anything the flush needs — what it needs is the
 * moment, and the moment is being called at all — so the event is accepted as
 * `object` and ignored, which is also what keeps the auth module from naming
 * the kernel's and the queue's event types.
 *
 * ## Why this is a class and not a closure
 *
 * `pulsar optimize` compiles the listener map ahead of time, and
 * `EventMapCompiler::parseListenerCallable()` records a listener as a class and
 * a method so `CompiledListenerProvider` can resolve it from the container at
 * dispatch time. A closure has neither. It threw
 * `closures cannot be compiled; use an invokable class or [class, method] array`
 * — and because `AuthWiring` registered a closure on `TerminateEvent` whenever
 * an audit logger existed, that is every stock deployment: `optimize` failed on
 * the framework's own wiring, not on anything the application had written.
 *
 * `AuthWiring` binds the instance under this class name, so the compiled map's
 * `container->get()` returns the listener that holds the configured sink rather
 * than constructing a second one.
 */
#[Internal]
final readonly class AuthorizationDecisionFlushListener
{
    public function __construct(
        private BufferedAuthorizationDecisionSink $sink,
    ) {}

    /**
     * @param object $event The drain point that was reached; its payload is not read.
     */
    public function __invoke(object $event): void
    {
        unset($event);

        $this->sink->flush();
    }
}
