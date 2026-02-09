<?php

declare(strict_types=1);

namespace Pulsar\Concurrency;

use Fiber;
use NoDiscard;
use Pulsar\Api\Api;
use Throwable;

use function array_key_exists;
use function array_keys;
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
     * @param array<int|string, callable(): mixed> $tasks Keyed callables to execute
     * @param positive-int $timeoutMs Maximum wall-clock time in milliseconds
     *
     * @return array<int|string, FanOutResult> Results keyed identically to $tasks
     */
    #[NoDiscard]
    public static function run(array $tasks, int $timeoutMs = 5000): array
    {
        if ($tasks === []) {
            return [];
        }

        $deadlineNs = hrtime(true) + ($timeoutMs * 1_000_000);

        /** @var array<int|string, Fiber<null, null, mixed, null>> $fibers */
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
                // Mark all remaining fibers as timed out
                foreach (array_keys($fibers) as $key) {
                    $results[$key] = new FanOutResult(
                        success: false,
                        timedOut: true,
                    );
                }

                break;
            }

            foreach ($fibers as $key => $fiber) {
                if ($fiber->isTerminated()) {
                    try {
                        $results[$key] = new FanOutResult(
                            success: true,
                            value: $fiber->getReturn(),
                        );
                    } catch (Throwable $e) {
                        $results[$key] = new FanOutResult(
                            success: false,
                            error: $e,
                        );
                    }
                    unset($fibers[$key]);

                    continue;
                }

                if ($fiber->isSuspended()) {
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

                // Check if fiber just terminated after resume
                if (isset($fibers[$key]) && $fiber->isTerminated()) {
                    try {
                        $results[$key] = new FanOutResult(
                            success: true,
                            value: $fiber->getReturn(),
                        );
                    } catch (Throwable $e) {
                        $results[$key] = new FanOutResult(
                            success: false,
                            error: $e,
                        );
                    }
                    unset($fibers[$key]);
                }
            }
        }

        // Preserve original key order
        $ordered = [];
        foreach ($tasks as $key => $_) {
            if (array_key_exists($key, $results)) {
                $ordered[$key] = $results[$key];
            }
        }

        return $ordered;
    }
}
