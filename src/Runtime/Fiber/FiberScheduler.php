<?php

declare(strict_types=1);

namespace Pulsar\Runtime\Fiber;

use Closure;
use Fiber;
use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Socket;
use Throwable;

use function count;

/**
 * Cooperative event loop using socket_select and Fibers.
 *
 * One Fiber per accepted connection. The main loop uses socket_select()
 * to determine which sockets are readable, then resumes the corresponding
 * Fiber. Fibers suspend when I/O would block.
 */
#[Internal]
final class FiberScheduler
{
    /**
     * @psalm-suppress TooManyTemplateParams
     *
     * @var array<int, Fiber<Socket, void, void, void>>
     */
    private array $fibers = [];

    /** @var array<int, Socket> Sockets keyed by resource ID */
    private array $sockets = [];

    /**
     * @param int $maxConcurrency Maximum concurrent Fibers (0 = unlimited)
     */
    public function __construct(
        private readonly int $maxConcurrency = 64,
        private readonly ?LoggerInterface $logger = null,
    ) {}

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
            $fiber->start($socket);
        } catch (Throwable $e) {
            $this->logger?->error('Fiber crashed during start', [
                'exception' => $e,
                'socket_id' => $id,
            ]);
            unset($this->fibers[$id], $this->sockets[$id]);

            return true;
        }

        // If the fiber completed immediately, clean up
        if ($fiber->isTerminated()) {
            unset($this->fibers[$id], $this->sockets[$id]);
        }

        return true;
    }

    /**
     * Run one tick of the event loop.
     *
     * Uses socket_select with the given timeout to check for readable sockets,
     * then resumes the corresponding Fibers. If a fiber throws, the exception
     * is caught and logged; the fiber is removed and the loop continues.
     *
     * @param float $timeoutSeconds Timeout for socket_select (default 0.1s)
     * @return int Number of fibers resumed
     */
    public function tick(float $timeoutSeconds = 0.1): int
    {
        if ($this->sockets === []) {
            return 0;
        }

        $read = array_values($this->sockets);
        $write = [];
        $except = [];

        $seconds = (int) $timeoutSeconds;
        $microseconds = (int) (($timeoutSeconds - (float) $seconds) * 1_000_000.0);

        /** @var list<Socket> $read */
        $ready = @socket_select($read, $write, $except, $seconds, $microseconds);

        if ($ready === false || $ready === 0) {
            return 0;
        }

        $resumed = 0;

        foreach ($read as $socket) {
            $id = spl_object_id($socket);

            if (!isset($this->fibers[$id])) {
                continue;
            }

            $fiber = $this->fibers[$id];

            if ($fiber->isSuspended()) {
                try {
                    $fiber->resume();
                } catch (Throwable $e) {
                    $this->logger?->error('Fiber crashed during tick', [
                        'exception' => $e,
                        'socket_id' => $id,
                    ]);
                    unset($this->fibers[$id], $this->sockets[$id]);
                    $resumed++;

                    continue;
                }
                $resumed++;
            }

            if ($fiber->isTerminated()) {
                unset($this->fibers[$id], $this->sockets[$id]);
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
     * Resumes all suspended Fibers and waits for them to terminate.
     * If a fiber throws, the exception is caught and logged; the fiber
     * is removed and draining continues for the remaining fibers.
     *
     * @param float $timeoutSeconds Maximum drain time
     * @return int Number of Fibers still active after drain
     */
    public function drain(float $timeoutSeconds = 5.0): int
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while ($this->fibers !== [] && microtime(true) < $deadline) {
            $this->tick(0.05);

            // Resume any suspended fibers that aren't waiting on I/O
            foreach ($this->fibers as $id => $fiber) {
                if ($fiber->isSuspended()) {
                    try {
                        $fiber->resume();
                    } catch (Throwable $e) {
                        $this->logger?->error('Fiber crashed during drain', [
                            'exception' => $e,
                            'socket_id' => $id,
                        ]);
                        unset($this->fibers[$id], $this->sockets[$id]);

                        continue;
                    }
                }

                if ($fiber->isTerminated()) {
                    unset($this->fibers[$id], $this->sockets[$id]);
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
        $id = spl_object_id($socket);
        unset($this->fibers[$id], $this->sockets[$id]);
    }

    /**
     * Clean up all tracked Fibers and sockets.
     */
    public function clear(): void
    {
        $this->fibers = [];
        $this->sockets = [];
    }
}
