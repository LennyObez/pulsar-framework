<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Runtime;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Pulsar\Runtime\ConnectionWarmable;
use Pulsar\Runtime\ConnectionWarmup;
use Pulsar\Runtime\ConnectionWarmupResult;
use RuntimeException;

#[CoversClass(ConnectionWarmup::class)]
#[CoversClass(ConnectionWarmupResult::class)]
final class ConnectionWarmupTest extends TestCase
{
    #[Test]
    public function warmAllSucceedsWithNoWarmables(): void
    {
        $warmup = new ConnectionWarmup();
        $result = $warmup->warmAll();

        self::assertSame(0, $result->total);
        self::assertSame(0, $result->succeeded);
        self::assertSame(0, $result->failed);
        self::assertTrue($result->allSucceeded());
        self::assertSame([], $result->errors);
    }

    #[Test]
    public function warmAllWarmsRegisteredConnections(): void
    {
        $warmup = new ConnectionWarmup(new NullLogger());

        $db = $this->createWarmable('database:default');
        $cache = $this->createWarmable('redis:cache');
        $warmup->register($db);
        $warmup->register($cache);

        self::assertSame(2, $warmup->count());

        $result = $warmup->warmAll();

        self::assertSame(2, $result->total);
        self::assertSame(2, $result->succeeded);
        self::assertSame(0, $result->failed);
        self::assertTrue($result->allSucceeded());
        self::assertGreaterThanOrEqual(0.0, $result->elapsedMs);
    }

    #[Test]
    public function warmAllReportsFailedConnections(): void
    {
        $warmup = new ConnectionWarmup(new NullLogger());

        $good = $this->createWarmable('database:default');
        $bad = $this->createFailingWarmable('redis:sessions', 'Connection refused');

        $warmup->register($good);
        $warmup->register($bad);

        $result = $warmup->warmAll();

        self::assertSame(2, $result->total);
        self::assertSame(1, $result->succeeded);
        self::assertSame(1, $result->failed);
        self::assertFalse($result->allSucceeded());
        self::assertCount(1, $result->errors);
        self::assertStringContainsString('redis:sessions', $result->errors[0]);
        self::assertStringContainsString('Connection refused', $result->errors[0]);
    }

    #[Test]
    public function warmAllContinuesAfterFailure(): void
    {
        $warmup = new ConnectionWarmup();

        $first = $this->createFailingWarmable('db:1', 'Timeout');
        $second = $this->createWarmable('db:2');
        $third = $this->createFailingWarmable('db:3', 'Refused');

        $warmup->register($first);
        $warmup->register($second);
        $warmup->register($third);

        $result = $warmup->warmAll();

        self::assertSame(3, $result->total);
        self::assertSame(1, $result->succeeded);
        self::assertSame(2, $result->failed);
        self::assertCount(2, $result->errors);
    }

    #[Test]
    public function countReflectsRegistrations(): void
    {
        $warmup = new ConnectionWarmup();

        self::assertSame(0, $warmup->count());

        $warmup->register($this->createWarmable('a'));
        self::assertSame(1, $warmup->count());

        $warmup->register($this->createWarmable('b'));
        self::assertSame(2, $warmup->count());
    }

    #[Test]
    public function elapsedMsIsNonNegative(): void
    {
        $warmup = new ConnectionWarmup();
        $warmup->register($this->createWarmable('db'));

        $result = $warmup->warmAll();

        self::assertGreaterThanOrEqual(0.0, $result->elapsedMs);
    }

    private function createWarmable(string $name): ConnectionWarmable
    {
        $warmable = $this->createStub(ConnectionWarmable::class);
        $warmable->method('connectionName')->willReturn($name);

        return $warmable;
    }

    private function createFailingWarmable(string $name, string $message): ConnectionWarmable
    {
        $warmable = $this->createStub(ConnectionWarmable::class);
        $warmable->method('connectionName')->willReturn($name);
        $warmable->method('warmConnection')->willThrowException(new RuntimeException($message));

        return $warmable;
    }
}
