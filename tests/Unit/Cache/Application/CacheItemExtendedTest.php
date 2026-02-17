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
final class CacheItemExtendedTest extends TestCase
{
    #[Test]
    public function hitItemHasCorrectState(): void
    {
        $expiration = new DateTimeImmutable('+1 hour');
        $item = CacheItem::hit('user.42', ['name' => 'Alice'], $expiration);

        self::assertSame('user.42', $item->getKey());
        self::assertSame(['name' => 'Alice'], $item->get());
        self::assertTrue($item->isHit());
        self::assertSame($expiration, $item->expiration);
    }

    #[Test]
    public function missItemHasCorrectState(): void
    {
        $item = CacheItem::miss('nonexistent');

        self::assertSame('nonexistent', $item->getKey());
        self::assertNull($item->get());
        self::assertFalse($item->isHit());
        self::assertNull($item->expiration);
    }

    #[Test]
    public function setUpdatesValueAndReturnsItem(): void
    {
        $item = CacheItem::miss('key');
        $result = $item->set('new-value');

        self::assertSame($item, $result);
        self::assertSame('new-value', $item->get());
    }

    #[Test]
    public function expiresAtSetsExpirationAndReturnsItem(): void
    {
        $item = CacheItem::miss('key');
        $expiration = new DateTimeImmutable('+2 hours');
        $result = $item->expiresAt($expiration);

        self::assertSame($item, $result);
        self::assertSame($expiration, $item->expiration);
    }

    #[Test]
    public function expiresAtWithNullClearsExpiration(): void
    {
        $item = CacheItem::hit('key', 'val', new DateTimeImmutable());
        $item->expiresAt(null);

        self::assertNull($item->expiration);
    }

    #[Test]
    public function expiresAfterWithIntegerSetsExpiration(): void
    {
        $item = CacheItem::miss('key');
        $result = $item->expiresAfter(3600);

        self::assertSame($item, $result);
        self::assertNotNull($item->expiration);
        // The expiration should be roughly 1 hour from now
        $diff = $item->expiration->getTimestamp() - new DateTimeImmutable()->getTimestamp();
        self::assertGreaterThanOrEqual(3598, $diff);
        self::assertLessThanOrEqual(3602, $diff);
    }

    #[Test]
    public function expiresAfterWithDateIntervalSetsExpiration(): void
    {
        $item = CacheItem::miss('key');
        $interval = new DateInterval('PT30M'); // 30 minutes
        $result = $item->expiresAfter($interval);

        self::assertSame($item, $result);
        self::assertNotNull($item->expiration);
    }

    #[Test]
    public function expiresAfterWithNullClearsExpiration(): void
    {
        $item = CacheItem::hit('key', 'val', new DateTimeImmutable());
        $item->expiresAfter(null);

        self::assertNull($item->expiration);
    }

    #[Test]
    public function hitWithNullExpirationIsValid(): void
    {
        $item = CacheItem::hit('key', 'value');

        self::assertTrue($item->isHit());
        self::assertNull($item->expiration);
    }

    #[Test]
    public function setCanStoreNullValue(): void
    {
        $item = CacheItem::miss('key');
        $item->set(null);

        self::assertNull($item->get());
    }
}
