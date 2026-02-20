<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cache\Application\Event;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\Application\Event\CacheClearEvent;
use Pulsar\Cache\Application\Event\CacheDeleteEvent;
use Pulsar\Cache\Application\Event\CacheErrorEvent;
use Pulsar\Cache\Application\Event\CacheEvent;
use Pulsar\Cache\Application\Event\CacheHitEvent;
use Pulsar\Cache\Application\Event\CacheMissEvent;
use Pulsar\Cache\Application\Event\CacheWriteEvent;
use RuntimeException;

#[CoversClass(CacheHitEvent::class)]
#[CoversClass(CacheMissEvent::class)]
#[CoversClass(CacheWriteEvent::class)]
#[CoversClass(CacheDeleteEvent::class)]
#[CoversClass(CacheErrorEvent::class)]
#[CoversClass(CacheClearEvent::class)]
#[CoversClass(CacheEvent::class)]
final class CacheEventTest extends TestCase
{
    #[Test]
    public function cacheHitEventHasCorrectOperationType(): void
    {
        $event = new CacheHitEvent('pool', 'array', 'hashed-key', 100);

        self::assertSame('hit', $event->operationType);
        self::assertSame('pool', $event->poolName);
        self::assertSame('array', $event->driverName);
        self::assertSame('hashed-key', $event->hashedKey);
        self::assertSame(100, $event->durationMicroseconds);
    }

    #[Test]
    public function cacheMissEventHasCorrectOperationType(): void
    {
        $event = new CacheMissEvent('pool', 'redis', 'hashed-key', 200);

        self::assertSame('miss', $event->operationType);
    }

    #[Test]
    public function cacheWriteEventHasCorrectOperationType(): void
    {
        $event = new CacheWriteEvent('pool', 'filesystem', 'hashed-key', 300);

        self::assertSame('write', $event->operationType);
    }

    #[Test]
    public function cacheDeleteEventHasCorrectOperationType(): void
    {
        $event = new CacheDeleteEvent('pool', 'database', 'hashed-key', 400);

        self::assertSame('delete', $event->operationType);
    }

    #[Test]
    public function cacheErrorEventHasCorrectOperationTypeAndErrorDetails(): void
    {
        $exception = new RuntimeException('connection lost');
        $event = new CacheErrorEvent('pool', 'redis', 'hashed-key', 500, 'connection lost', $exception);

        self::assertSame('error', $event->operationType);
        self::assertSame('connection lost', $event->errorMessage);
        self::assertSame(RuntimeException::class, $event->errorClass);
    }

    #[Test]
    public function cacheErrorEventWithoutException(): void
    {
        $event = new CacheErrorEvent('pool', 'redis', 'hashed-key', 500, 'unknown error');

        self::assertSame('error', $event->operationType);
        self::assertSame('unknown error', $event->errorMessage);
        self::assertNull($event->errorClass);
    }

    #[Test]
    public function cacheClearEventHasCorrectOperationType(): void
    {
        $event = new CacheClearEvent('pool', 'array', 150);

        self::assertSame('clear', $event->operationType);
        self::assertSame('pool', $event->poolName);
        self::assertSame('array', $event->driverName);
        self::assertSame('', $event->hashedKey);
        self::assertSame(150, $event->durationMicroseconds);
    }

    #[Test]
    public function cacheHitEventPreservesAllProperties(): void
    {
        $event = new CacheHitEvent('my-pool', 'redis', 'key-hash-abc', 42);

        self::assertSame('my-pool', $event->poolName);
        self::assertSame('redis', $event->driverName);
        self::assertSame('key-hash-abc', $event->hashedKey);
        self::assertSame(42, $event->durationMicroseconds);
        self::assertSame('hit', $event->operationType);
    }

    #[Test]
    public function cacheMissEventPreservesAllProperties(): void
    {
        $event = new CacheMissEvent('session-pool', 'file', 'key-xyz', 999);

        self::assertSame('session-pool', $event->poolName);
        self::assertSame('file', $event->driverName);
        self::assertSame('key-xyz', $event->hashedKey);
        self::assertSame(999, $event->durationMicroseconds);
        self::assertSame('miss', $event->operationType);
    }

    #[Test]
    public function cacheWriteEventPreservesAllProperties(): void
    {
        $event = new CacheWriteEvent('data-pool', 'memcached', 'key-write', 50);

        self::assertSame('data-pool', $event->poolName);
        self::assertSame('memcached', $event->driverName);
        self::assertSame('key-write', $event->hashedKey);
        self::assertSame(50, $event->durationMicroseconds);
        self::assertSame('write', $event->operationType);
    }

    #[Test]
    public function cacheDeleteEventPreservesAllProperties(): void
    {
        $event = new CacheDeleteEvent('temp-pool', 'apcu', 'key-del', 10);

        self::assertSame('temp-pool', $event->poolName);
        self::assertSame('apcu', $event->driverName);
        self::assertSame('key-del', $event->hashedKey);
        self::assertSame(10, $event->durationMicroseconds);
        self::assertSame('delete', $event->operationType);
    }

    #[Test]
    public function cacheErrorEventPreservesAllProperties(): void
    {
        $exception = new RuntimeException('timeout');
        $event = new CacheErrorEvent('err-pool', 'redis', 'key-err', 5000, 'timeout', $exception);

        self::assertSame('err-pool', $event->poolName);
        self::assertSame('redis', $event->driverName);
        self::assertSame('key-err', $event->hashedKey);
        self::assertSame(5000, $event->durationMicroseconds);
        self::assertSame('error', $event->operationType);
        self::assertSame('timeout', $event->errorMessage);
        self::assertSame(RuntimeException::class, $event->errorClass);
    }

    #[Test]
    public function cacheEventsWithZeroDuration(): void
    {
        $hit = new CacheHitEvent('pool', 'array', 'key', 0);
        self::assertSame(0, $hit->durationMicroseconds);

        $miss = new CacheMissEvent('pool', 'array', 'key', 0);
        self::assertSame(0, $miss->durationMicroseconds);
    }

    #[Test]
    public function cacheEventsWithEmptyStringValues(): void
    {
        $event = new CacheHitEvent('', '', '', 0);

        self::assertSame('', $event->poolName);
        self::assertSame('', $event->driverName);
        self::assertSame('', $event->hashedKey);
    }
}
