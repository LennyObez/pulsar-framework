<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\RateLimit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\RateLimit\SlidingWindowRateLimiter;

#[CoversClass(SlidingWindowRateLimiter::class)]
final class SlidingWindowRateLimiterTest extends TestCase
{
    #[Test]
    public function firstHitIsAllowed(): void
    {
        $limiter = new SlidingWindowRateLimiter(maxAttempts: 5, windowSeconds: 60);

        $result = $limiter->hit('client-1');

        self::assertTrue($result->allowed);
        self::assertSame(5, $result->limit);
        self::assertSame(4, $result->remaining);
        self::assertSame(0, $result->retryAfter);
    }

    #[Test]
    public function hitsUpToMaxAreAllowed(): void
    {
        $limiter = new SlidingWindowRateLimiter(maxAttempts: 3, windowSeconds: 60);

        for ($i = 0; $i < 3; $i++) {
            $result = $limiter->hit('client-1');
            self::assertTrue($result->allowed, "Hit {$i} should be allowed");
        }

        self::assertSame(0, $result->remaining);
    }

    #[Test]
    public function hitBeyondMaxIsDenied(): void
    {
        $limiter = new SlidingWindowRateLimiter(maxAttempts: 2, windowSeconds: 60);

        $limiter->hit('client-1');
        $limiter->hit('client-1');
        $result = $limiter->hit('client-1');

        self::assertFalse($result->allowed);
        self::assertSame(0, $result->remaining);
        self::assertGreaterThan(0, $result->retryAfter);
    }

    #[Test]
    public function attemptsReturnsCurrentCount(): void
    {
        $limiter = new SlidingWindowRateLimiter(maxAttempts: 10, windowSeconds: 60);

        self::assertSame(0, $limiter->attempts('client-1'));

        $limiter->hit('client-1');
        $limiter->hit('client-1');

        self::assertSame(2, $limiter->attempts('client-1'));
    }

    #[Test]
    public function attemptsForUnknownKeyIsZero(): void
    {
        $limiter = new SlidingWindowRateLimiter(maxAttempts: 10, windowSeconds: 60);

        self::assertSame(0, $limiter->attempts('unknown'));
    }

    #[Test]
    public function resetClearsHitsForKey(): void
    {
        $limiter = new SlidingWindowRateLimiter(maxAttempts: 3, windowSeconds: 60);

        $limiter->hit('client-1');
        $limiter->hit('client-1');
        self::assertSame(2, $limiter->attempts('client-1'));

        $limiter->reset('client-1');
        self::assertSame(0, $limiter->attempts('client-1'));
    }

    #[Test]
    public function keysAreIsolated(): void
    {
        $limiter = new SlidingWindowRateLimiter(maxAttempts: 2, windowSeconds: 60);

        $limiter->hit('client-a');
        $limiter->hit('client-a');
        $limiter->hit('client-b');

        self::assertSame(2, $limiter->attempts('client-a'));
        self::assertSame(1, $limiter->attempts('client-b'));
    }

    #[Test]
    public function resetOnlyAffectsTargetKey(): void
    {
        $limiter = new SlidingWindowRateLimiter(maxAttempts: 5, windowSeconds: 60);

        $limiter->hit('client-a');
        $limiter->hit('client-b');

        $limiter->reset('client-a');

        self::assertSame(0, $limiter->attempts('client-a'));
        self::assertSame(1, $limiter->attempts('client-b'));
    }

    #[Test]
    public function deniedRetryAfterIsPositive(): void
    {
        $limiter = new SlidingWindowRateLimiter(maxAttempts: 1, windowSeconds: 30);

        $limiter->hit('client-1');
        $result = $limiter->hit('client-1');

        self::assertFalse($result->allowed);
        self::assertGreaterThanOrEqual(1, $result->retryAfter);
        self::assertLessThanOrEqual(30, $result->retryAfter);
    }

    #[Test]
    public function remainingDecrementsWithEachHit(): void
    {
        $limiter = new SlidingWindowRateLimiter(maxAttempts: 3, windowSeconds: 60);

        $r1 = $limiter->hit('key');
        self::assertSame(2, $r1->remaining);

        $r2 = $limiter->hit('key');
        self::assertSame(1, $r2->remaining);

        $r3 = $limiter->hit('key');
        self::assertSame(0, $r3->remaining);
    }
}
