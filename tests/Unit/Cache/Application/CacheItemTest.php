<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cache\Application;

use DateInterval;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\Application\CacheItem;

#[CoversClass(CacheItem::class)]
final class CacheItemTest extends TestCase
{
    #[Test]
    public function missCreatesItemWithIsHitFalseAndNullValue(): void
    {
        $item = CacheItem::miss('test-key');

        self::assertFalse($item->isHit());
        self::assertNull($item->get());
    }

    #[Test]
    public function hitCreatesItemWithIsHitTrueAndValue(): void
    {
        $item = CacheItem::hit('test-key', 'cached-value');

        self::assertTrue($item->isHit());
        self::assertSame('cached-value', $item->get());
    }

    #[Test]
    public function setUpdatesValue(): void
    {
        $item = CacheItem::miss('test-key');
        $result = $item->set('new-value');

        self::assertSame('new-value', $item->get());
        self::assertSame($item, $result);
    }

    #[Test]
    public function expiresAtSetsExpiration(): void
    {
        $item = CacheItem::miss('test-key');
        $expiration = new DateTimeImmutable('+1 hour');

        $result = $item->expiresAt($expiration);

        self::assertSame($expiration, $item->expiration);
        self::assertSame($item, $result);
    }

    #[Test]
    public function expiresAfterWithIntSetsFutureExpiration(): void
    {
        $before = new DateTimeImmutable('+3599 seconds');
        $item = CacheItem::miss('test-key');

        $result = $item->expiresAfter(3600);

        $expiration = $item->expiration;
        self::assertNotNull($expiration);
        self::assertGreaterThanOrEqual($before->getTimestamp(), $expiration->getTimestamp());
        self::assertSame($item, $result);
    }

    #[Test]
    public function expiresAfterWithNullRemovesExpiration(): void
    {
        $item = CacheItem::miss('test-key');
        $item->expiresAfter(3600);

        $item->expiresAfter(null);

        self::assertNull($item->expiration);
    }

    #[Test]
    public function getKeyReturnsKey(): void
    {
        $item = CacheItem::miss('my-cache-key');

        self::assertSame('my-cache-key', $item->getKey());
    }

    #[Test]
    public function getExpirationReturnsSetExpiration(): void
    {
        $expiration = new DateTimeImmutable('+2 hours');
        $item = CacheItem::hit('test-key', 'value', $expiration);

        self::assertSame($expiration, $item->expiration);
    }

    #[Test]
    public function expiresAfterWithDateIntervalSetsCorrectExpiration(): void
    {
        $item = CacheItem::miss('test-key');
        $before = new DateTimeImmutable('+59 minutes');

        $result = $item->expiresAfter(new DateInterval('PT1H'));

        $expiration = $item->expiration;
        self::assertNotNull($expiration);
        self::assertGreaterThanOrEqual($before->getTimestamp(), $expiration->getTimestamp());
        self::assertSame($item, $result);
    }
}
