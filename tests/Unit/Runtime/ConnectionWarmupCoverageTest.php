<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Runtime;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Runtime\ConnectionWarmable;
use Pulsar\Runtime\ConnectionWarmup;
use Pulsar\Runtime\ConnectionWarmupResult;
use RuntimeException;

#[CoversClass(ConnectionWarmup::class)]
#[CoversClass(ConnectionWarmupResult::class)]
final class ConnectionWarmupCoverageTest extends TestCase
{
    #[Test]
    public function warmAllWithNoWarmablesReturnsEmptyResult(): void
    {
        $warmup = new ConnectionWarmup();

        $result = $warmup->warmAll();

        self::assertSame(0, $result->total);
        self::assertSame(0, $result->succeeded);
        self::assertSame(0, $result->failed);
        self::assertSame([], $result->errors);
        self::assertTrue($result->allSucceeded());
    }

    #[Test]
    public function warmAllSucceedsForAllWarmables(): void
    {
        $warmup = new ConnectionWarmup();

        $warmup->register($this->createWarmable('database', succeeds: true));
        $warmup->register($this->createWarmable('cache', succeeds: true));

        $result = $warmup->warmAll();

        self::assertSame(2, $result->total);
        self::assertSame(2, $result->succeeded);
        self::assertSame(0, $result->failed);
        self::assertSame([], $result->errors);
        self::assertTrue($result->allSucceeded());
        self::assertGreaterThanOrEqual(0.0, $result->elapsedMs);
    }

    #[Test]
    public function warmAllHandlesFailedWarmable(): void
    {
        $warmup = new ConnectionWarmup();

        $warmup->register($this->createWarmable('database', succeeds: true));
        $warmup->register($this->createWarmable('redis', succeeds: false, error: 'Connection refused'));
        $warmup->register($this->createWarmable('cache', succeeds: true));

        $result = $warmup->warmAll();

        self::assertSame(3, $result->total);
        self::assertSame(2, $result->succeeded);
        self::assertSame(1, $result->failed);
        self::assertFalse($result->allSucceeded());
        self::assertCount(1, $result->errors);
        self::assertStringContainsString('redis', $result->errors[0]);
        self::assertStringContainsString('Connection refused', $result->errors[0]);
    }

    #[Test]
    public function warmAllAllFail(): void
    {
        $warmup = new ConnectionWarmup();

        $warmup->register($this->createWarmable('db1', succeeds: false, error: 'timeout'));
        $warmup->register($this->createWarmable('db2', succeeds: false, error: 'refused'));

        $result = $warmup->warmAll();

        self::assertSame(2, $result->total);
        self::assertSame(0, $result->succeeded);
        self::assertSame(2, $result->failed);
        self::assertFalse($result->allSucceeded());
        self::assertCount(2, $result->errors);
    }

    #[Test]
    public function countReturnsRegisteredCount(): void
    {
        $warmup = new ConnectionWarmup();

        self::assertSame(0, $warmup->count());

        $warmup->register($this->createWarmable('db', succeeds: true));
        self::assertSame(1, $warmup->count());

        $warmup->register($this->createWarmable('cache', succeeds: true));
        self::assertSame(2, $warmup->count());
    }

    #[Test]
    public function connectionWarmupResultAllSucceededReflectsFailedCount(): void
    {
        $success = new ConnectionWarmupResult(
            total: 3,
            succeeded: 3,
            failed: 0,
            errors: [],
            elapsedMs: 10.5,
        );
        self::assertTrue($success->allSucceeded());

        $failure = new ConnectionWarmupResult(
            total: 3,
            succeeded: 2,
            failed: 1,
            errors: ['error'],
            elapsedMs: 15.0,
        );
        self::assertFalse($failure->allSucceeded());
    }

    private function createWarmable(string $name, bool $succeeds, string $error = ''): ConnectionWarmable
    {
        return new class ($name, $succeeds, $error) implements ConnectionWarmable {
            public function __construct(
                private readonly string $name,
                private readonly bool $succeeds,
                private readonly string $error,
            ) {}

            public function warmConnection(): void
            {
                if (!$this->succeeds) {
                    throw new RuntimeException($this->error);
                }
            }

            public function connectionName(): string
            {
                return $this->name;
            }
        };
    }
}
