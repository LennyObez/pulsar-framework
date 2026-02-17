<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Internal\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\Application\Lock\LockHandle;
use Pulsar\Cache\Application\Lock\LockInterface;
use Pulsar\Cache\Application\TaggedCacheInterface;
use Pulsar\Extension\Cms\Internal\Security\CmsRateLimiter;
use RuntimeException;

#[CoversClass(CmsRateLimiter::class)]
final class CmsRateLimiterTest extends TestCase
{
    private TaggedCacheInterface $cache;
    private LockInterface $lock;

    protected function setUp(): void
    {
        $this->cache = $this->createStub(TaggedCacheInterface::class);

        $handle = new LockHandle(
            resource: 'test',
            token: 'tok',
            acquiredAt: 1.0,
            ttlSeconds: 60,
        );

        $this->lock = $this->createStub(LockInterface::class);
        $this->lock->method('acquire')->willReturn($handle);
        $this->lock->method('release')->willReturn(true);
    }

    #[Test]
    public function firstAttemptIsAllowed(): void
    {
        $this->cache->method('get')->willReturn(null);

        $limiter = new CmsRateLimiter($this->cache, $this->lock);

        self::assertTrue($limiter->attempt('test_key', 5, 60));
    }

    #[Test]
    public function attemptWithinLimitIsAllowed(): void
    {
        $this->cache->method('get')->willReturn('3');

        $limiter = new CmsRateLimiter($this->cache, $this->lock);

        self::assertTrue($limiter->attempt('test_key', 5, 60));
    }

    #[Test]
    public function attemptAtLimitIsAllowed(): void
    {
        $this->cache->method('get')->willReturn('4');

        $limiter = new CmsRateLimiter($this->cache, $this->lock);

        self::assertTrue($limiter->attempt('test_key', 5, 60));
    }

    #[Test]
    public function attemptExceedingLimitIsBlocked(): void
    {
        $this->cache->method('get')->willReturn('5');

        $limiter = new CmsRateLimiter($this->cache, $this->lock);

        self::assertFalse($limiter->attempt('test_key', 5, 60));
    }

    #[Test]
    public function nonNumericCacheValueTreatedAsFirstAttempt(): void
    {
        $this->cache->method('get')->willReturn('invalid');

        $limiter = new CmsRateLimiter($this->cache, $this->lock);

        self::assertTrue($limiter->attempt('test_key', 1, 60));
    }

    #[Test]
    public function attemptAcquiresAndReleasesLock(): void
    {
        $handle = new LockHandle(
            resource: 'test_lock',
            token: 'tok_123',
            acquiredAt: 1.0,
            ttlSeconds: 60,
        );

        $lock = $this->createMock(LockInterface::class);
        $lock->expects(self::once())
            ->method('acquire')
            ->with(
                self::stringStartsWith('cms_rate_lock:'),
                60,
                1000,
            )
            ->willReturn($handle);

        $lock->expects(self::once())
            ->method('release')
            ->with($handle)
            ->willReturn(true);

        $this->cache->method('get')->willReturn(null);

        $limiter = new CmsRateLimiter($this->cache, $lock);

        self::assertTrue($limiter->attempt('test_key', 5, 60));
    }

    #[Test]
    public function attemptWritesIncrementedCountInsideLock(): void
    {
        $cache = $this->createMock(TaggedCacheInterface::class);
        $cache->method('get')->willReturn('2');

        // Verify the cache is written with the incremented value (3)
        $cache->expects(self::once())
            ->method('set')
            ->with(
                self::stringStartsWith('cms_rate:'),
                '3',
                ['cms_rate_limit'],
                60,
            )
            ->willReturn(true);

        $limiter = new CmsRateLimiter($cache, $this->lock);

        self::assertTrue($limiter->attempt('test_key', 5, 60));
    }

    #[Test]
    public function attemptWritesEvenWhenLimitExceeded(): void
    {
        $cache = $this->createMock(TaggedCacheInterface::class);
        $cache->method('get')->willReturn('5');

        // Even when exceeding the limit, the count is still written
        $cache->expects(self::once())
            ->method('set')
            ->with(
                self::stringStartsWith('cms_rate:'),
                '6',
                ['cms_rate_limit'],
                60,
            )
            ->willReturn(true);

        $limiter = new CmsRateLimiter($cache, $this->lock);

        self::assertFalse($limiter->attempt('test_key', 5, 60));
    }

    #[Test]
    public function lockIsReleasedEvenWhenCacheThrows(): void
    {
        $lock = $this->createMock(LockInterface::class);

        $handle = new LockHandle(
            resource: 'test_lock',
            token: 'tok',
            acquiredAt: 1.0,
            ttlSeconds: 60,
        );

        $lock->method('acquire')->willReturn($handle);

        // Lock release must still be called even when cache throws
        $lock->expects(self::once())
            ->method('release')
            ->with($handle)
            ->willReturn(true);

        $cache = $this->createStub(TaggedCacheInterface::class);
        $cache->method('get')->willThrowException(new RuntimeException('cache failure'));

        $limiter = new CmsRateLimiter($cache, $lock);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cache failure');

        $limiter->attempt('test_key', 5, 60);
    }

    #[Test]
    public function singleAttemptAllowedWhenMaxIsOne(): void
    {
        $this->cache->method('get')->willReturn(null);

        $limiter = new CmsRateLimiter($this->cache, $this->lock);

        self::assertTrue($limiter->attempt('test_key', 1, 60));
    }

    #[Test]
    public function secondAttemptBlockedWhenMaxIsOne(): void
    {
        $this->cache->method('get')->willReturn('1');

        $limiter = new CmsRateLimiter($this->cache, $this->lock);

        self::assertFalse($limiter->attempt('test_key', 1, 60));
    }
}
