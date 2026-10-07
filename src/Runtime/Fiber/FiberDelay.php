<?php

declare(strict_types=1);

namespace Pulsar\Runtime\Fiber;

use Pulsar\Api\Internal;

/**
 * The value a fiber suspends with to ask the scheduler to resume it after a
 * deadline, rather than when its socket becomes readable.
 *
 * {@see FiberScheduler} distinguishes the two suspend protocols by the suspend
 * value: `Fiber::suspend()` (null) means "resume on I/O readiness" — the
 * original behaviour — while `Fiber::suspend(new FiberDelay(...))` registers a
 * timer. This is how a cooperative lock wait yields the worker to other
 * connections instead of blocking it in usleep().
 */
#[Internal]
final readonly class FiberDelay
{
    /**
     * @param int $deadlineNs Monotonic hrtime(true) value at which the fiber
     *     should be resumed.
     */
    public function __construct(
        public int $deadlineNs,
    ) {}
}
