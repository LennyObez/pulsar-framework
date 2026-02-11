<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Internal;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\Application\TaggedCacheInterface;
use Pulsar\Extension\Cms\Internal\Security\CmsRateLimiter;

#[CoversClass(CmsRateLimiter::class)]
final class CmsRateLimiterTest extends TestCase
{
    #[Test]
    public function attemptAllowsWithinLimit(): void
    {
        $cache = new InMemoryTaggedCache();
        $limiter = new CmsRateLimiter($cache);

        // First attempt should be allowed
        self::assertTrue($limiter->attempt('test_op:user1', 5, 60));
    }

    #[Test]
    public function attemptBlocksWhenLimitExceeded(): void
    {
        $cache = new InMemoryTaggedCache();
        $limiter = new CmsRateLimiter($cache);

        // Allow up to 2 attempts
        self::assertTrue($limiter->attempt('op:u1', 2, 60));
        self::assertTrue($limiter->attempt('op:u1', 2, 60));
        // Third attempt should be blocked
        self::assertFalse($limiter->attempt('op:u1', 2, 60));
    }

    #[Test]
    public function attemptTracksDifferentKeysIndependently(): void
    {
        $cache = new InMemoryTaggedCache();
        $limiter = new CmsRateLimiter($cache);

        self::assertTrue($limiter->attempt('op:user1', 1, 60));
        self::assertFalse($limiter->attempt('op:user1', 1, 60));
        // Different key should still be allowed
        self::assertTrue($limiter->attempt('op:user2', 1, 60));
    }
}

/**
 * @internal Test double for TaggedCacheInterface
 */
final class InMemoryTaggedCache implements TaggedCacheInterface
{
    /** @var array<string, mixed> */
    private array $store = [];

    public function get(string $key): mixed
    {
        return $this->store[$key] ?? null;
    }

    public function set(string $key, mixed $value, array $tags, ?int $ttlSeconds = null): bool
    {
        $this->store[$key] = $value;

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->store[$key]);

        return true;
    }

    public function invalidateTag(string $tag): void
    {
        $this->store = [];
    }

    public function invalidateTags(array $tags): void
    {
        $this->store = [];
    }
}
