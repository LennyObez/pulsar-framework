<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\RateLimit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\RateLimit\RateLimitPolicy;
use Pulsar\Http\RateLimit\RateLimitTier;

#[CoversClass(RateLimitPolicy::class)]
final class RateLimitPolicyTest extends TestCase
{
    #[Test]
    public function startsWithNoRulesAndNoDefault(): void
    {
        $policy = new RateLimitPolicy();

        self::assertSame(0, $policy->ruleCount());
        self::assertNull($policy->defaultTier());
    }

    #[Test]
    public function setDefaultStoresDefaultTier(): void
    {
        $policy = new RateLimitPolicy();
        $tier = new RateLimitTier(maxAttempts: 60, windowSeconds: 60);

        $policy->setDefault($tier);

        self::assertSame($tier, $policy->defaultTier());
    }

    #[Test]
    public function addRuleIncreasesCount(): void
    {
        $policy = new RateLimitPolicy();
        $tier = new RateLimitTier(maxAttempts: 5, windowSeconds: 300);

        $policy->addRule('#^/api/login$#', 'anonymous', $tier);

        self::assertSame(1, $policy->ruleCount());
    }

    #[Test]
    public function resolveMatchesFirstMatchingRuleForTier(): void
    {
        $policy = new RateLimitPolicy();
        $loginTier = new RateLimitTier(maxAttempts: 5, windowSeconds: 300);
        $apiTier = new RateLimitTier(maxAttempts: 100, windowSeconds: 60);

        $policy->addRule('#^/api/login$#', 'anonymous', $loginTier);
        $policy->addRule('#^/api/#', 'anonymous', $apiTier);

        $resolved = $policy->resolve('/api/login', 'anonymous');

        self::assertSame($loginTier, $resolved);
    }

    #[Test]
    public function resolveSkipsRulesWithNonMatchingTier(): void
    {
        $policy = new RateLimitPolicy();
        $anonTier = new RateLimitTier(maxAttempts: 30, windowSeconds: 60);
        $premiumTier = new RateLimitTier(maxAttempts: 1000, windowSeconds: 60);

        $policy->addRule('#^/api/#', 'anonymous', $anonTier);
        $policy->addRule('#^/api/#', 'premium', $premiumTier);

        $resolved = $policy->resolve('/api/data', 'premium');

        self::assertSame($premiumTier, $resolved);
    }

    #[Test]
    public function resolveReturnsDefaultWhenNoRuleMatches(): void
    {
        $policy = new RateLimitPolicy();
        $defaultTier = new RateLimitTier(maxAttempts: 60, windowSeconds: 60);
        $specificTier = new RateLimitTier(maxAttempts: 5, windowSeconds: 300);

        $policy->setDefault($defaultTier);
        $policy->addRule('#^/api/login$#', 'anonymous', $specificTier);

        $resolved = $policy->resolve('/api/users', 'anonymous');

        self::assertSame($defaultTier, $resolved);
    }

    #[Test]
    public function resolveReturnsNullWhenNoMatchAndNoDefault(): void
    {
        $policy = new RateLimitPolicy();
        $tier = new RateLimitTier(maxAttempts: 5, windowSeconds: 300);
        $policy->addRule('#^/api/login$#', 'anonymous', $tier);

        $resolved = $policy->resolve('/api/users', 'authenticated');

        self::assertNull($resolved);
    }

    #[Test]
    public function resolveReturnsDefaultForNonMatchingPattern(): void
    {
        $policy = new RateLimitPolicy();
        $defaultTier = new RateLimitTier(maxAttempts: 60, windowSeconds: 60);
        $policy->setDefault($defaultTier);

        $resolved = $policy->resolve('/unknown', 'anonymous');

        self::assertSame($defaultTier, $resolved);
    }

    #[Test]
    public function multipleRulesFirstMatchWins(): void
    {
        $policy = new RateLimitPolicy();
        $strict = new RateLimitTier(maxAttempts: 5, windowSeconds: 300);
        $lenient = new RateLimitTier(maxAttempts: 100, windowSeconds: 60);

        $policy->addRule('#^/api/auth#', 'api', $strict);
        $policy->addRule('#^/api/#', 'api', $lenient);

        self::assertSame($strict, $policy->resolve('/api/auth/token', 'api'));
        self::assertSame($lenient, $policy->resolve('/api/data', 'api'));
    }
}
