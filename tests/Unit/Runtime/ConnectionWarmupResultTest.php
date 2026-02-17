<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Runtime;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Runtime\ConnectionWarmupResult;

#[CoversClass(ConnectionWarmupResult::class)]
final class ConnectionWarmupResultTest extends TestCase
{
    #[Test]
    public function constructionStoresAllProperties(): void
    {
        $result = new ConnectionWarmupResult(
            total: 5,
            succeeded: 4,
            failed: 1,
            errors: ['Redis connection timed out'],
            elapsedMs: 123.45,
        );

        self::assertSame(5, $result->total);
        self::assertSame(4, $result->succeeded);
        self::assertSame(1, $result->failed);
        self::assertSame(['Redis connection timed out'], $result->errors);
        self::assertSame(123.45, $result->elapsedMs);
    }

    #[Test]
    public function allSucceededReturnsTrueWhenNoFailures(): void
    {
        $result = new ConnectionWarmupResult(
            total: 3,
            succeeded: 3,
            failed: 0,
            errors: [],
            elapsedMs: 50.0,
        );

        self::assertTrue($result->allSucceeded());
    }

    #[Test]
    public function allSucceededReturnsFalseWhenFailuresExist(): void
    {
        $result = new ConnectionWarmupResult(
            total: 3,
            succeeded: 2,
            failed: 1,
            errors: ['Database connection failed'],
            elapsedMs: 200.0,
        );

        self::assertFalse($result->allSucceeded());
    }

    #[Test]
    public function emptyWarmupAllSucceeded(): void
    {
        $result = new ConnectionWarmupResult(
            total: 0,
            succeeded: 0,
            failed: 0,
            errors: [],
            elapsedMs: 0.0,
        );

        self::assertTrue($result->allSucceeded());
    }
}
