<?php

declare(strict_types=1);

namespace Pulsar\Runtime\Fiber;

use Fiber;
use Pulsar\Api\Api;

use function hrtime;
use function usleep;

/**
 * A short sleep that YIELDS the worker when it can, and blocks only when it
 * cannot.
 *
 * Lock-acquisition spin loops (Redis, filesystem) and the cache stampede
 * loser-poll need to pause briefly between retries. A plain `usleep` there
 * stalls the entire cooperative worker: under {@see FiberScheduler} one
 * connection waiting on a contended lock would freeze every other connection —
 * and, worse, prevent the fiber that HOLDS the lock from being resumed to
 * release it.
 *
 * When called inside a fiber that {@see FiberScheduler} is actively driving,
 * this suspends the fiber with a {@see FiberDelay} timer, handing the worker to
 * other connections until the deadline. Everywhere else — the classic
 * one-process-per-request path, a {@see \Pulsar\Concurrency\FanOut} task, or any
 * fiber no scheduler will resume on a timer — it falls back to `usleep`, which
 * is correct there and never risks a fiber that nothing wakes.
 * @api
 */
#[Api(since: '1.0.0')]
final class CooperativeSleep
{
    public static function forMilliseconds(int $milliseconds): void
    {
        if ($milliseconds <= 0) {
            return;
        }

        if (Fiber::getCurrent() !== null && FiberScheduler::isDriving()) {
            Fiber::suspend(new FiberDelay(hrtime(true) + $milliseconds * 1_000_000));

            return;
        }

        usleep($milliseconds * 1000);
    }
}
