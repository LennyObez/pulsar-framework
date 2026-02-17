<?php

declare(strict_types=1);

namespace Pulsar\Support;

use Closure;
use InvalidArgumentException;
use Pulsar\Api\Api;
use RuntimeException;
use Throwable;

use function max;
use function min;
use function sprintf;
use function usleep;

/**
 * Standalone utility functions: value(), retry(), once(), tap().
 *
 * These are pure helper functions, not tied to any framework state.
 */
#[Api(since: '1.0.0')]
final class Helpers
{
    private function __construct() {}

    /**
     * Resolve a value: call closures, pass scalars through.
     *
     * @template T
     *
     * @param T|Closure(): T $value
     *
     * @return T
     */
    public static function value(mixed $value): mixed
    {
        return $value instanceof Closure ? $value() : $value;
    }

    /**
     * Retry a callback with exponential backoff.
     *
     * @template T
     *
     * @param Closure(): T $callback
     * @param int $times Maximum number of attempts (must be >= 1)
     * @param int $baseDelayMs Base delay between attempts in milliseconds
     * @param float $multiplier Delay multiplier per attempt
     * @param (Closure(Throwable): bool)|null $when Only retry if this returns true
     * @param (Closure(int): void)|null $sleepFn Custom sleep function (receives microseconds): for testing
     *
     * @return T
     *
     * @throws InvalidArgumentException If times < 1 or baseDelayMs < 0
     * @throws RuntimeException The last exception thrown by the callback
     */
    public static function retry(
        Closure $callback,
        int $times = 3,
        int $baseDelayMs = 100,
        float $multiplier = 2.0,
        ?Closure $when = null,
        ?Closure $sleepFn = null,
    ): mixed {
        if ($times < 1) {
            throw new InvalidArgumentException(sprintf('Retry times must be >= 1, got %d', $times));
        }

        if ($baseDelayMs < 0) {
            throw new InvalidArgumentException(sprintf('Base delay must be >= 0, got %d', $baseDelayMs));
        }

        $sleeper = $sleepFn ?? static function (int $us): void {
            usleep($us);
        };
        $lastException = null;

        for ($attempt = 1; $attempt <= $times; $attempt++) {
            try {
                return $callback();
            } catch (Throwable $e) {
                $lastException = $e;

                if ($when !== null && !$when($e)) {
                    throw $e;
                }

                if ($attempt < $times) {
                    $delayMs = (int) ($baseDelayMs * ($multiplier ** ($attempt - 1)));
                    $delayMs = max(0, min($delayMs, 60_000)); // cap at 60 seconds
                    $sleeper($delayMs * 1000);
                }
            }
        }

        /** @var Throwable $lastException Always set since $times >= 1 and loop body always throws or returns */
        throw $lastException;
    }

    /**
     * Memoize a callable; return cached result on repeat calls.
     *
     * @template T
     *
     * @param Closure(): T $callback
     *
     * @return Closure(): T
     */
    public static function once(Closure $callback): Closure
    {
        $called = false;
        $result = null;

        return static function () use ($callback, &$called, &$result): mixed {
            if (!$called) {
                $result = $callback();
                $called = true;
            }

            return $result;
        };
    }

    /**
     * Apply a callback to a value, then return the original value.
     *
     * @template T
     *
     * @param T $value
     * @param (Closure(T): void)|null $callback
     *
     * @return T
     */
    public static function tap(mixed $value, ?Closure $callback = null): mixed
    {
        if ($callback !== null) {
            $callback($value);
        }

        return $value;
    }
}
