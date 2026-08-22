<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\RateLimit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\RateLimit\AdaptiveRateLimiter;
use Pulsar\Http\RateLimit\EndpointRateLimitPolicy;
use Pulsar\Http\RateLimit\SlidingWindowRateLimiter;
use Pulsar\Http\RateLimit\ThreatLevel;

#[CoversClass(AdaptiveRateLimiter::class)]
final class AdaptiveRateLimiterTest extends TestCase
{
    private function createLimiter(int $baseLimit = 100, int $windowSeconds = 60): AdaptiveRateLimiter
    {
        $base = new SlidingWindowRateLimiter(maxAttempts: 1000, windowSeconds: $windowSeconds);

        return new AdaptiveRateLimiter($base, $baseLimit);
    }

    #[Test]
    public function hitAllowsRequestsWithinBaseLimit(): void
    {
        $limiter = $this->createLimiter(baseLimit: 10);

        $result = $limiter->hit('client-1');

        self::assertTrue($result->allowed);
        self::assertSame(10, $result->limit);
    }

    #[Test]
    public function hitRejectsRequestsExceedingAdaptiveLimit(): void
    {
        $base = new SlidingWindowRateLimiter(maxAttempts: 1000, windowSeconds: 60);
        $limiter = new AdaptiveRateLimiter($base, baseLimit: 3);

        for ($i = 0; $i < 3; $i++) {
            $result = $limiter->hit('client-1');
            self::assertTrue($result->allowed, "Hit {$i} should be allowed");
        }

        $result = $limiter->hit('client-1');
        self::assertFalse($result->allowed);
    }

    #[Test]
    public function threatLevelReducesEffectiveLimit(): void
    {
        $limiter = $this->createLimiter(baseLimit: 100);

        $limiter->escalateThreatLevel(ThreatLevel::High);

        $result = $limiter->hit('client-1');

        self::assertSame(50, $result->limit);
    }

    #[Test]
    public function currentThreatLevelDefaultsToNormal(): void
    {
        $limiter = $this->createLimiter();

        self::assertSame(ThreatLevel::Normal, $limiter->currentThreatLevel());
    }

    #[Test]
    public function escalateThreatLevelUpdatesCurrent(): void
    {
        $limiter = $this->createLimiter();

        $limiter->escalateThreatLevel(ThreatLevel::Critical);

        self::assertSame(ThreatLevel::Critical, $limiter->currentThreatLevel());
    }

    #[Test]
    public function criticalThreatLevelQuartersTheLimit(): void
    {
        $limiter = $this->createLimiter(baseLimit: 100);

        $limiter->escalateThreatLevel(ThreatLevel::Critical);

        $result = $limiter->hit('client-1');
        self::assertSame(25, $result->limit);
    }

    #[Test]
    public function endpointPolicyOverridesBaseLimit(): void
    {
        $limiter = $this->createLimiter(baseLimit: 100);
        $limiter->registerEndpointPolicy(new EndpointRateLimitPolicy(
            pattern: '/api/login',
            maxAttempts: 5,
            windowSeconds: 300,
        ));

        $result = $limiter->hit('/api/login:192.168.1.1');

        self::assertSame(5, $result->limit);
    }

    #[Test]
    public function keyWithoutEndpointUsesBaseLimit(): void
    {
        $limiter = $this->createLimiter(baseLimit: 100);
        $limiter->registerEndpointPolicy(new EndpointRateLimitPolicy(
            pattern: '/api/login',
            maxAttempts: 5,
            windowSeconds: 300,
        ));

        $result = $limiter->hit('simple-key');

        self::assertSame(100, $result->limit);
    }

    #[Test]
    public function successfulHitsImproveReputation(): void
    {
        $limiter = $this->createLimiter(baseLimit: 100);

        $limiter->hit('client-1');
        $limiter->hit('client-1');

        $reputation = $limiter->getReputation('client-1');
        self::assertGreaterThan(1.0, $reputation->score);
    }

    #[Test]
    public function getReputationReturnsDefaultForUnknownKey(): void
    {
        $limiter = $this->createLimiter();

        $reputation = $limiter->getReputation('unknown');

        self::assertSame(1.0, $reputation->score);
        self::assertSame(0, $reputation->successCount);
        self::assertSame(0, $reputation->violationCount);
    }

    #[Test]
    public function attemptsProxiesToBaseLimiter(): void
    {
        $limiter = $this->createLimiter(baseLimit: 100);

        $limiter->hit('client-1');
        $limiter->hit('client-1');

        self::assertSame(2, $limiter->attempts('client-1'));
    }

    #[Test]
    public function resetClearsAttemptsAndReputation(): void
    {
        $limiter = $this->createLimiter(baseLimit: 100);

        $limiter->hit('client-1');
        $limiter->hit('client-1');

        $limiter->reset('client-1');

        self::assertSame(0, $limiter->attempts('client-1'));
        $reputation = $limiter->getReputation('client-1');
        self::assertSame(1.0, $reputation->score);
    }

    #[Test]
    public function violationsReduceReputation(): void
    {
        $base = new SlidingWindowRateLimiter(maxAttempts: 1000, windowSeconds: 60);
        $limiter = new AdaptiveRateLimiter($base, baseLimit: 2);

        $limiter->hit('client-1');
        $limiter->hit('client-1');
        // Third hit exceeds effective limit, triggers reputation degradation
        $limiter->hit('client-1');

        $reputation = $limiter->getReputation('client-1');
        self::assertLessThan(1.0, $reputation->score);
    }

    #[Test]
    public function effectiveLimitNeverDropsBelowOne(): void
    {
        $limiter = $this->createLimiter(baseLimit: 1);
        $limiter->escalateThreatLevel(ThreatLevel::Critical);

        $result = $limiter->hit('client-1');

        self::assertGreaterThanOrEqual(1, $result->limit);
    }

    #[Test]
    public function multipleThreatLevelEscalationsReplaceEachOther(): void
    {
        $limiter = $this->createLimiter(baseLimit: 100);

        $limiter->escalateThreatLevel(ThreatLevel::Critical);
        $limiter->escalateThreatLevel(ThreatLevel::Normal);

        $result = $limiter->hit('client-1');
        self::assertSame(100, $result->limit);
    }
}
