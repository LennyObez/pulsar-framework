<?php

declare(strict_types=1);

namespace Pulsar\Auth\Internal\Authorization;

use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\AuthorizationDecisionSinkInterface;

/**
 * A decision sink that holds what it is handed and writes it out on demand.
 *
 * {@see AuthorizationDecisionSinkInterface} says only that a decision can be
 * given to a sink. It says nothing about WHEN the sink writes, because for most
 * sinks the answer is "immediately" and there is nothing to arrange. A sink that
 * defers has a second event in its life — the moment the deferral ends — and
 * something has to reach it: {@see AuthorizationDecisionFlushListener} does,
 * from the drain point the kernel and the queue worker announce.
 *
 * That listener used to name {@see BufferedAuthorizationDecisionSink}, which is
 * `final`, so the only buffering strategy the framework could ever flush was the
 * one it ships. A deployment that batches per transaction, or writes through a
 * different store, could implement the sink contract but could not be driven by
 * the listener that exists to drive it. This is the missing half of the contract,
 * not a new capability.
 *
 * Module-private, like the sink and the listener: the drain point is arranged by
 * {@see \Pulsar\Core\Wiring\AuthWiring} and is not something an application wires
 * for itself.
 */
#[Internal(reason: 'The deferred half of the decision-sink contract; wired by AuthWiring')]
interface BufferedDecisionSinkInterface extends AuthorizationDecisionSinkInterface
{
    /**
     * Write out everything held, and go back to holding nothing.
     *
     * Must be safe to call when nothing is held, and safe to call from inside a
     * flush already in progress: the drain points that trigger it are not
     * mutually exclusive, and a sink whose own writes produce decisions will
     * re-enter this method.
     */
    public function flush(): void;

    /**
     * How many decisions are held but not yet written.
     */
    public function buffered(): int;
}
