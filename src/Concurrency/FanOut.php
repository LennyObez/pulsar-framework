<?php

declare(strict_types=1);

namespace Pulsar\Concurrency;

use Fiber;
use InvalidArgumentException;
use NoDiscard;
use Pulsar\Api\Api;
use Throwable;

use function array_key_exists;
use function array_keys;
use function assert;
use function hrtime;

/**
 * Infrastructure-only concurrent execution primitive using Fibers.
 *
 * Executes an array of callables concurrently via cooperative multitasking.
 * Each callable runs in its own Fiber and is round-robin resumed until
 * completion or timeout.
 *
 * This is an infrastructure primitive intended for framework internals
 * (preflight checks, health probes, cache warming). It is NOT a general-
 * purpose concurrency tool for business logic. Extensions should prefer
 * sequential execution or the queue system for I/O-bound work.
 *
 * @see FanOutResult For the per-task outcome structure
 * @api
 */
#[Api(since: '1.0.0')]
final class FanOut
{
    /**
     * Execute callables concurrently using Fibers.
     *
     * Each callable is wrapped in a Fiber that suspends after each resume,
     * allowing round-robin scheduling. Exceptions are caught per-Fiber and
     * wrapped in a {@see FanOutResult}. Tasks that do not complete within
     * the timeout are marked as timed out.
     *
     * Timeout is checked once per full round-robin pass. A task that calls
     * {@see Fiber::suspend()} many times within a single pass will consume the
     * corresponding scheduling quantum before the deadline is re-evaluated.
     *
     * Timed-out tasks are abandoned, not forcibly terminated. Their Fibers are
     * garbage-collected when this method returns. Callables that acquire
     * resources (connections, handles, locks) must use `try`/`finally`
     * internally to guarantee cleanup, since an abandoned Fiber's body never
     * resumes past its current suspension point.
     *
     * $timeoutMs must be a positive integer (milliseconds). The value is
     * validated at runtime by the guard below, which throws on zero or negative
     * input. The PHPDoc type is intentionally a plain `int` rather than
     * `positive-int`: a `positive-int` docblock causes the static analysers to
     * narrow the method body and treat the runtime guard as unreachable dead
     * code, which would silently drop the protection. The runtime guard is the
     * source of truth for this contract and covers every caller, including
     * dynamic/computed values, reflection, and callers analysed at a level that
     * does not enforce the narrower type.
     *
     * @param array<int|string, callable(): mixed> $tasks Keyed callables to execute
     * @param int $timeoutMs Maximum wall-clock time in milliseconds; must be > 0
     *
     * @return array<int|string, FanOutResult> Results keyed identically to $tasks
     *
     * @throws InvalidArgumentException If $timeoutMs is not a positive integer
     */
    #[NoDiscard]
    public static function run(array $tasks, int $timeoutMs = 5000): array
    {
        if ($timeoutMs <= 0) {
            throw new InvalidArgumentException(
                "timeoutMs must be a positive integer, got {$timeoutMs}.",
            );
        }

        if ($tasks === []) {
            return [];
        }

        $deadlineNs = hrtime(true) + ($timeoutMs * 1_000_000);

        /** @var array<int|string, Fiber<mixed, mixed, mixed, mixed>> $fibers
         * @psalm-suppress TooManyTemplateParams Psalm 5.x doesn't support Fiber generics
         */
        $fibers = [];

        /** @var array<int|string, FanOutResult> $results */
        $results = [];

        // Create and start all Fibers
        foreach ($tasks as $key => $callable) {
            $fibers[$key] = new Fiber(static function () use ($callable): mixed {
                Fiber::suspend(); // Yield immediately so all fibers are created before execution begins
                return $callable();
            });
        }

        // Start each Fiber (they will suspend immediately)
        foreach ($fibers as $key => $fiber) {
            try {
                $fiber->start();
            } catch (Throwable $e) {
                $results[$key] = new FanOutResult(
                    success: false,
                    error: $e,
                );
                unset($fibers[$key]);
            }
        }

        // Round-robin resume until all complete or timeout
        while ($fibers !== []) {
            if (hrtime(true) >= $deadlineNs) {
                // Harvest any fiber that already completed before the deadline
                // fired this pass; mark only genuinely unfinished ones as timed out.
                foreach ($fibers as $key => $fiber) {
                    if ($fiber->isTerminated()) {
                        $results[$key] = new FanOutResult(
                            success: true,
                            value: $fiber->getReturn(),
                        );

                        continue;
                    }

                    $results[$key] = new FanOutResult(
                        success: false,
                        timedOut: true,
                    );
                }

                break;
            }

            foreach ($fibers as $key => $fiber) {
                if ($fiber->isTerminated()) {
                    // A fiber that returned via an uncaught exception is removed
                    // from $fibers in the resume catch below, so any terminated
                    // fiber still tracked here returned normally — getReturn()
                    // cannot throw under single-threaded PHP semantics.
                    $results[$key] = new FanOutResult(
                        success: true,
                        value: $fiber->getReturn(),
                    );
                    unset($fibers[$key]);

                    continue;
                }

                if (!$fiber->isSuspended()) {
                    // Not suspended and not terminated means the fiber was just
                    // resumed to completion this pass; it will be detected as
                    // terminated and harvested on the next round-robin pass.
                    continue;
                }

                try {
                    $fiber->resume();
                } catch (Throwable $e) {
                    $results[$key] = new FanOutResult(
                        success: false,
                        error: $e,
                    );
                    unset($fibers[$key]);
                }
            }
        }

        // Preserve original key order. Every task key is guaranteed to have a
        // result: each fiber is either harvested on completion, removed with a
        // result on failure during start/resume, or marked timed out.
        $ordered = [];
        foreach (array_keys($tasks) as $key) {
            assert(array_key_exists($key, $results));
            $ordered[$key] = $results[$key];
        }

        return $ordered;
    }
}
