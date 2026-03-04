<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\RateLimit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\RateLimit\RateLimitResult;

#[CoversClass(RateLimitResult::class)]
final class RateLimitResultTest extends TestCase
{
    #[Test]
    public function allowedResultIsNotExceeded(): void
    {
        $result = new RateLimitResult(allowed: true, limit: 100, remaining: 99, retryAfter: 0);

        self::assertTrue($result->allowed);
        self::assertFalse($result->exceeded());
        self::assertSame(100, $result->limit);
        self::assertSame(99, $result->remaining);
        self::assertSame(0, $result->retryAfter);
    }

    #[Test]
    public function deniedResultIsExceeded(): void
    {
        $result = new RateLimitResult(allowed: false, limit: 100, remaining: 0, retryAfter: 30);

        self::assertFalse($result->allowed);
        self::assertTrue($result->exceeded());
        self::assertSame(0, $result->remaining);
        self::assertSame(30, $result->retryAfter);
    }

    #[Test]
    public function exceededIsInverseOfAllowed(): void
    {
        $allowed = new RateLimitResult(allowed: true, limit: 10, remaining: 5, retryAfter: 0);
        $denied = new RateLimitResult(allowed: false, limit: 10, remaining: 0, retryAfter: 60);

        self::assertSame(!$allowed->allowed, $allowed->exceeded());
        self::assertSame(!$denied->allowed, $denied->exceeded());
    }
}
