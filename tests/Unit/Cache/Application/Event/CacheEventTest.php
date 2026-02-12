<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cache\Application\Event;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\Application\Event\CacheDeleteEvent;
use Pulsar\Cache\Application\Event\CacheErrorEvent;
use Pulsar\Cache\Application\Event\CacheHitEvent;
use Pulsar\Cache\Application\Event\CacheMissEvent;
use Pulsar\Cache\Application\Event\CacheWriteEvent;
use RuntimeException;

#[CoversClass(CacheHitEvent::class)]
#[CoversClass(CacheMissEvent::class)]
#[CoversClass(CacheWriteEvent::class)]
#[CoversClass(CacheDeleteEvent::class)]
#[CoversClass(CacheErrorEvent::class)]
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
}
