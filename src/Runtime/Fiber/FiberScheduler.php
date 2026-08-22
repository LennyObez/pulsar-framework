<?php

declare(strict_types=1);

namespace Pulsar\Runtime\Fiber;

use Closure;
use Fiber;
use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Socket;
use Throwable;

use function array_values;
use function count;
use function hrtime;
use function min;

/**
 * Cooperative event loop using socket_select and Fibers.
 *
 * One Fiber per accepted connection. The main loop uses socket_select()
 * to determine which sockets are readable, then resumes the corresponding
 * Fiber. Fibers suspend when I/O would block.
 *
 * Two suspend protocols, distinguished by the value a fiber suspends with:
 *  - `Fiber::suspend()` (null): resume when the fiber's socket is readable.
 *  - `Fiber::suspend(new FiberDelay($deadlineNs))`: resume once the deadline
 *    passes (a timer). This is what {@see CooperativeSleep} uses so a lock-wait
 *    spin yields the worker instead of blocking it in usleep().
 */
#[Internal]
final class FiberScheduler
{
    /**
     * Connection fibers keyed by socket id. phpstan requires Fiber's four
     * generics; psalm's stub declares none, so psalm.xml suppresses the
     * resulting TooManyTemplateParams for this file (a stub disagreement, not a
     * real type error).
     *
     * @var array<int, Fiber<Socket, void, void, void>>
     */
    private array $fibers = [];

    /** @var array<int, Socket> Sockets keyed by resource ID */
    private array $sockets = [];

    /** @var array<int, int> hrtime(true) deadline per timer-suspended fiber, keyed by socket id */
    private array $timers = [];

    /**
     * Depth of the current start()/resume() nesting. > 0 exactly while this
     * scheduler is running a fiber, so {@see CooperativeSleep} knows a
     * FiberDelay suspend will actually be honoured. Static because the sleeping
     * fiber, deep in application code, has no reference to the scheduler.
     */
    private static int $drivingDepth = 0;

    /**
     * @param int $maxConcurrency Maximum concurrent Fibers (0 = unlimited)
     */
    public function __construct(
        private readonly int $maxConcurrency = 64,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * Whether a scheduler is currently driving a fiber (inside start()/resume()).
     */
    public static function isDriving(): bool
    {
        return self::$drivingDepth > 0;
    }

    /**
     * Spawn a new Fiber for the given socket and handler.
     *
     * If the fiber throws during start, the exception is caught and logged.
     * The fiber is cleaned up and the scheduler continues operating.
     *
     * @param Closure(Socket): void $handler
     * @return bool True if spawned, false if at concurrency limit
     */
    public function spawn(Socket $socket, Closure $handler): bool
    {
        if ($this->maxConcurrency > 0 && count($this->fibers) >= $this->maxConcurrency) {
            return false;
        }

        $id = spl_object_id($socket);
        $fiber = new Fiber($handler);
        $this->fibers[$id] = $fiber;
        $this->sockets[$id] = $socket;

        try {
            /** @var mixed $suspendValue The value the fiber suspended with (null = I/O wait). */
            $suspendValue = $this->drive(static fn(): mixed => $fiber->start($socket));
        } catch (Throwable $e) {
            $this->logger?->error('Fiber crashed during start', [
                'exception' => $e,
                'socket_id' => $id,
            ]);
            $this->forget($id);

            return true;
        }

        if ($fiber->isTerminated()) {
            $this->forget($id);
        } else {
            $this->registerSuspend($id, $suspendValue);
        }

        return true;
    }

    /**
     * Run one tick of the event loop.
     *
     * socket_select waits up to the requested timeout — or less, when a timer is
     * due sooner, so a sleeping fiber wakes on time — then readable-socket fibers
     * and expired-timer fibers are resumed. If a fiber throws, it is caught,
     * logged, removed, and the loop continues.
     *
     * @param float $timeoutSeconds Timeout for socket_select (default 0.1s)
     * @return int Number of fibers resumed
     */
    public function tick(float $timeoutSeconds = 0.1): int
    {
        if ($this->fibers === []) {
            return 0;
        }

        $resumed = 0;
        $timeout = $this->boundByNearestTimer($timeoutSeconds);

        if ($this->sockets !== []) {
            $read = array_values($this->sockets);
            $write = [];
            $except = [];

            $seconds = (int) $timeout;
            $microseconds = (int) (($timeout - (float) $seconds) * 1_000_000.0);

            /** @var list<Socket> $read */
            $ready = @socket_select($read, $write, $except, $seconds, $microseconds);

            if ($ready !== false && $ready > 0) {
                foreach ($read as $socket) {
                    $id = spl_object_id($socket);

                    if (isset($this->fibers[$id]) && $this->fibers[$id]->isSuspended()) {
                        $this->resumeFiber($id, 'tick');
                        $resumed++;
                    }
                }
            }
        }

        // Resume fibers whose timer has elapsed. Collect the due ids first so
        // resumeFiber() (which mutates $this->timers) cannot disturb iteration.
        if ($this->timers !== []) {
            $now = hrtime(true);
            $due = [];

            foreach ($this->timers as $id => $deadlineNs) {
                if ($deadlineNs <= $now) {
                    $due[] = $id;
                }
            }

            foreach ($due as $id) {
                if (isset($this->fibers[$id]) && $this->fibers[$id]->isSuspended()) {
                    $this->resumeFiber($id, 'tick');
                    $resumed++;
                }
            }
        }

        return $resumed;
    }

    /**
     * Get the count of active Fibers.
     */
    public function activeFiberCount(): int
    {
        return count($this->fibers);
    }

    /**
     * Whether the scheduler can accept more connections.
     */
    public function hasCapacity(): bool
    {
        if ($this->maxConcurrency === 0) {
            return true;
        }

        return count($this->fibers) < $this->maxConcurrency;
    }

    /**
     * Drain all active Fibers within the given timeout.
     *
     * Force-resumes every suspended Fiber (ignoring its timer) and waits for
     * them to terminate. A fiber that throws is caught, logged, removed, and
     * draining continues for the rest.
     *
     * @param float $timeoutSeconds Maximum drain time
     * @return int Number of Fibers still active after drain
     */
    public function drain(float $timeoutSeconds = 5.0): int
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while ($this->fibers !== [] && microtime(true) < $deadline) {
            $this->tick(0.05);

            foreach ($this->fibers as $id => $fiber) {
                if ($fiber->isSuspended()) {
                    $this->resumeFiber($id, 'drain');
                }
            }
        }

        return count($this->fibers);
    }

    /**
     * Remove a specific socket and its Fiber.
     */
    public function remove(Socket $socket): void
    {
        $this->forget(spl_object_id($socket));
    }

    /**
     * Clean up all tracked Fibers and sockets.
     */
    public function clear(): void
    {
        $this->fibers = [];
        $this->sockets = [];
        $this->timers = [];
    }

    /**
     * Resume a suspended fiber, re-registering its next suspension (or cleaning
     * it up on termination / crash). Idempotent for a non-suspended fiber.
     */
    private function resumeFiber(int $id, string $context = 'resume'): void
    {
        $fiber = $this->fibers[$id] ?? null;

        if ($fiber === null || !$fiber->isSuspended()) {
            return;
        }

        // Consuming this suspension: drop any timer it was waiting on.
        unset($this->timers[$id]);

        try {
            /** @var mixed $suspendValue The value the fiber suspended with (null = I/O wait). */
            $suspendValue = $this->drive(static fn(): mixed => $fiber->resume());
        } catch (Throwable $e) {
            $this->logger?->error('Fiber crashed during ' . $context, [
                'exception' => $e,
                'socket_id' => $id,
            ]);
            $this->forget($id);

            return;
        }

        if ($fiber->isTerminated()) {
            $this->forget($id);
        } else {
            $this->registerSuspend($id, $suspendValue);
        }
    }

    /**
     * Record what a just-suspended fiber is waiting on: a timer (FiberDelay) or,
     * for any other suspend value, socket readability.
     */
    private function registerSuspend(int $id, mixed $suspendValue): void
    {
        if ($suspendValue instanceof FiberDelay) {
            $this->timers[$id] = $suspendValue->deadlineNs;
        } else {
            unset($this->timers[$id]);
        }
    }

    /**
     * Shorten the select timeout so a due (or soon-due) timer is not overslept.
     */
    private function boundByNearestTimer(float $requested): float
    {
        if ($this->timers === []) {
            return $requested;
        }

        $untilNs = min($this->timers) - hrtime(true);

        if ($untilNs <= 0) {
            return 0.0;
        }

        return min($requested, $untilNs / 1_000_000_000.0);
    }

    private function forget(int $id): void
    {
        unset($this->fibers[$id], $this->sockets[$id], $this->timers[$id]);
    }

    /**
     * Run a fiber start/resume with the driving flag raised, so a
     * FiberDelay-based sleep inside it knows the scheduler will resume it.
     */
    private function drive(callable $fn): mixed
    {
        self::$drivingDepth++;

        try {
            return $fn();
        } finally {
            self::$drivingDepth--;
        }
    }
}
