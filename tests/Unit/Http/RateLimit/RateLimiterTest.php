<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\RateLimit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\RateLimit\RateLimiter;
use Pulsar\Http\RateLimit\RateLimitResult;

#[CoversClass(RateLimiter::class)]
#[CoversClass(RateLimitResult::class)]
final class RateLimiterTest extends TestCase
{
    #[Test]
    public function allowsRequestsWithinLimit(): void
    {
        $limiter = new RateLimiter(maxAttempts: 3, windowSeconds: 60);

        $result = $limiter->hit('key');

        self::assertTrue($result->allowed);
        self::assertFalse($result->exceeded());
        self::assertSame(3, $result->limit);
        self::assertSame(2, $result->remaining);
        self::assertSame(0, $result->retryAfter);
    }

    #[Test]
    public function decrementsRemainingOnEachHit(): void
    {
        $limiter = new RateLimiter(maxAttempts: 3, windowSeconds: 60);

        $first = $limiter->hit('key');
        $second = $limiter->hit('key');
        $third = $limiter->hit('key');

        self::assertSame(2, $first->remaining);
        self::assertSame(1, $second->remaining);
        self::assertSame(0, $third->remaining);
        self::assertTrue($third->allowed);
    }

    #[Test]
    public function rejectsRequestsOverLimit(): void
    {
        $limiter = new RateLimiter(maxAttempts: 2, windowSeconds: 60);

        $limiter->hit('key');
        $limiter->hit('key');
        $result = $limiter->hit('key');

        self::assertFalse($result->allowed);
        self::assertTrue($result->exceeded());
        self::assertSame(0, $result->remaining);
        self::assertGreaterThan(0, $result->retryAfter);
    }

    #[Test]
    public function tracksKeysIndependently(): void
    {
        $limiter = new RateLimiter(maxAttempts: 1, windowSeconds: 60);

        $limiter->hit('key-a');
        $resultA = $limiter->hit('key-a');
        $resultB = $limiter->hit('key-b');

        self::assertFalse($resultA->allowed);
        self::assertTrue($resultB->allowed);
    }

    #[Test]
    public function attemptsReturnsCurrentCount(): void
    {
        $limiter = new RateLimiter(maxAttempts: 10, windowSeconds: 60);

        self::assertSame(0, $limiter->attempts('key'));

        $limiter->hit('key');
        $limiter->hit('key');

        self::assertSame(2, $limiter->attempts('key'));
    }

    #[Test]
    public function resetClearsCounter(): void
    {
        $limiter = new RateLimiter(maxAttempts: 2, windowSeconds: 60);

        $limiter->hit('key');
        $limiter->hit('key');
        $limiter->reset('key');

        $result = $limiter->hit('key');

        self::assertTrue($result->allowed);
        self::assertSame(1, $limiter->attempts('key'));
    }

    #[Test]
    public function resultExceededIsFalseWhenAllowed(): void
    {
        $result = new RateLimitResult(allowed: true, limit: 10, remaining: 5, retryAfter: 0);

        self::assertFalse($result->exceeded());
    }

    #[Test]
    public function resultExceededIsTrueWhenNotAllowed(): void
    {
        $result = new RateLimitResult(allowed: false, limit: 10, remaining: 0, retryAfter: 30);

        self::assertTrue($result->exceeded());
    }
}
