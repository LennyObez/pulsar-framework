<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Support;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Support\Helpers;
use RuntimeException;
use Throwable;

final class HelpersTest extends TestCase
{
    // ── value() ───────────────────────────────────────────────────────

    #[Test]
    public function valuePassesScalarThrough(): void
    {
        self::assertSame(42, Helpers::value(42));
        self::assertSame('hello', Helpers::value('hello'));
        self::assertTrue(Helpers::value(true));
        self::assertNull(Helpers::value(null));
    }

    #[Test]
    public function valueResolvesClosure(): void
    {
        $result = Helpers::value(fn(): int => 42);

        self::assertSame(42, $result);
    }

    #[Test]
    public function valuePassesArrayThrough(): void
    {
        self::assertSame([1, 2, 3], Helpers::value([1, 2, 3]));
    }

    // ── retry() ───────────────────────────────────────────────────────

    #[Test]
    public function retrySucceedsOnFirstAttempt(): void
    {
        $attempts = 0;

        $result = Helpers::retry(
            callback: function () use (&$attempts): string {
                $attempts++;
                return 'ok';
            },
            times: 3,
            sleepFn: fn(int $us) => null,
        );

        self::assertSame('ok', $result);
        self::assertSame(1, $attempts);
    }

    #[Test]
    public function retryRetriesOnFailure(): void
    {
        $attempts = 0;

        $result = Helpers::retry(
            callback: function () use (&$attempts): string {
                $attempts++;
                if ($attempts < 3) {
                    throw new RuntimeException('fail');
                }
                return 'ok';
            },
            times: 3,
            sleepFn: fn(int $us) => null,
        );

        self::assertSame('ok', $result);
        self::assertSame(3, $attempts);
    }

    #[Test]
    public function retryThrowsLastExceptionWhenAllAttemptsFail(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('attempt 3');

        $attempt = 0;
        Helpers::retry(
            callback: function () use (&$attempt): never {
                $attempt++;
                throw new RuntimeException('attempt ' . $attempt);
            },
            times: 3,
            sleepFn: fn(int $us) => null,
        );
    }

    #[Test]
    public function retryRejectsInvalidTimes(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Helpers::retry(fn() => null, times: 0);
    }

    #[Test]
    public function retryRejectsNegativeDelay(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Helpers::retry(fn() => null, times: 1, baseDelayMs: -1);
    }

    #[Test]
    public function retryAppliesExponentialBackoff(): void
    {
        $sleepTimes = [];
        $attempt = 0;

        try {
            Helpers::retry(
                callback: function () use (&$attempt): never {
                    $attempt++;
                    throw new RuntimeException('fail');
                },
                times: 4,
                baseDelayMs: 100,
                multiplier: 2.0,
                sleepFn: function (int $us) use (&$sleepTimes): void {
                    $sleepTimes[] = $us;
                },
            );
        } catch (RuntimeException) {
            // Expected
        }

        // attempt 1 => 100ms * 2^0 = 100ms = 100000us
        // attempt 2 => 100ms * 2^1 = 200ms = 200000us
        // attempt 3 => 100ms * 2^2 = 400ms = 400000us
        self::assertSame([100_000, 200_000, 400_000], $sleepTimes);
    }

    #[Test]
    public function retryWhenFilterStopsRetryingOnUnmatchedException(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Helpers::retry(
            callback: fn() => throw new InvalidArgumentException('bad arg'),
            times: 3,
            when: fn(Throwable $e): bool => $e instanceof RuntimeException,
            sleepFn: fn(int $us) => null,
        );
    }

    // ── once() ────────────────────────────────────────────────────────

    #[Test]
    public function onceCallsCallbackOnlyOnce(): void
    {
        $counter = 0;
        $memoized = Helpers::once(function () use (&$counter): int {
            $counter++;
            return 42;
        });

        self::assertSame(42, $memoized());
        self::assertSame(42, $memoized());
        self::assertSame(42, $memoized());
        self::assertSame(1, $counter);
    }

    #[Test]
    public function onceCachesNullResult(): void
    {
        $counter = 0;
        $memoized = Helpers::once(function () use (&$counter): mixed {
            $counter++;
            return null;
        });

        self::assertNull($memoized());
        self::assertNull($memoized());
        self::assertSame(1, $counter);
    }

    #[Test]
    public function onceScopesAreSeparate(): void
    {
        $fn1 = Helpers::once(fn(): int => 1);
        $fn2 = Helpers::once(fn(): int => 2);

        self::assertSame(1, $fn1());
        self::assertSame(2, $fn2());
    }

    // ── tap() ─────────────────────────────────────────────────────────

    #[Test]
    public function tapReturnsOriginalValue(): void
    {
        $tapped = null;
        $result = Helpers::tap(42, function (int $v) use (&$tapped): void {
            $tapped = $v;
        });

        self::assertSame(42, $result);
        self::assertSame(42, $tapped);
    }

    #[Test]
    public function tapWithoutCallbackReturnsValue(): void
    {
        self::assertSame('hello', Helpers::tap('hello'));
    }

    #[Test]
    public function tapDoesNotModifyReturnValue(): void
    {
        $arr = [1, 2, 3];
        $result = Helpers::tap($arr, function (array $v): void {
            // Attempt to modify — should not affect returned value
            $v[] = 4;
        });

        self::assertSame([1, 2, 3], $result);
    }
}
