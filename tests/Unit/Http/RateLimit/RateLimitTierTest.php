<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\RateLimit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\RateLimit\RateLimitTier;

#[CoversClass(RateLimitTier::class)]
final class RateLimitTierTest extends TestCase
{
    #[Test]
    public function constructorSetsProperties(): void
    {
        $tier = new RateLimitTier(maxAttempts: 100, windowSeconds: 60);

        self::assertSame(100, $tier->maxAttempts);
        self::assertSame(60, $tier->windowSeconds);
    }

    #[Test]
    public function differentTiersHaveDifferentLimits(): void
    {
        $anonymous = new RateLimitTier(maxAttempts: 30, windowSeconds: 60);
        $premium = new RateLimitTier(maxAttempts: 1000, windowSeconds: 60);

        self::assertGreaterThan($anonymous->maxAttempts, $premium->maxAttempts);
    }
}
