<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\RateLimit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\RateLimit\EndpointRateLimitPolicy;

#[CoversClass(EndpointRateLimitPolicy::class)]
final class EndpointRateLimitPolicyTest extends TestCase
{
    #[Test]
    public function constructorSetsProperties(): void
    {
        $policy = new EndpointRateLimitPolicy(
            pattern: '/api/login',
            maxAttempts: 5,
            windowSeconds: 300,
        );

        self::assertSame('/api/login', $policy->pattern);
        self::assertSame(5, $policy->maxAttempts);
        self::assertSame(300, $policy->windowSeconds);
    }

    #[Test]
    public function fromArrayCreatesInstanceWithAllFields(): void
    {
        $policy = EndpointRateLimitPolicy::fromArray([
            'pattern' => '/api/auth',
            'max_attempts' => 10,
            'window_seconds' => 120,
        ]);

        self::assertSame('/api/auth', $policy->pattern);
        self::assertSame(10, $policy->maxAttempts);
        self::assertSame(120, $policy->windowSeconds);
    }

    #[Test]
    public function fromArrayUsesDefaultsForMissingFields(): void
    {
        $policy = EndpointRateLimitPolicy::fromArray([]);

        self::assertSame('', $policy->pattern);
        self::assertSame(60, $policy->maxAttempts);
        self::assertSame(60, $policy->windowSeconds);
    }

    #[Test]
    public function fromArrayUsesDefaultsForPartialData(): void
    {
        $policy = EndpointRateLimitPolicy::fromArray([
            'pattern' => '/api/search',
        ]);

        self::assertSame('/api/search', $policy->pattern);
        self::assertSame(60, $policy->maxAttempts);
        self::assertSame(60, $policy->windowSeconds);
    }
}
