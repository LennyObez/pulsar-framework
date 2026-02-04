<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Resilience;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Resilience\RetryResult;
use RuntimeException;

#[CoversClass(RetryResult::class)]
final class RetryResultTest extends TestCase
{
    #[Test]
    public function successFactoryCreatesSuccessfulResult(): void
    {
        $result = RetryResult::success('value', 1, []);

        self::assertTrue($result->succeeded);
        self::assertSame(1, $result->attempts);
        self::assertSame('value', $result->result);
        self::assertNull($result->lastException);
        self::assertSame([], $result->attemptDelays);
    }

    #[Test]
    public function exhaustedFactoryCreatesFailedResult(): void
    {
        $exception = new RuntimeException('failed');
        $delays = [100, 200];

        $result = RetryResult::exhausted(3, $exception, $delays);

        self::assertFalse($result->succeeded);
        self::assertSame(3, $result->attempts);
        self::assertNull($result->result);
        self::assertSame($exception, $result->lastException);
        self::assertSame([100, 200], $result->attemptDelays);
    }

    #[Test]
    public function fieldAccessReturnsConstructorValues(): void
    {
        $exception = new RuntimeException('test');
        $delays = [50];

        $result = new RetryResult(
            succeeded: false,
            attempts: 2,
            result: 'partial',
            lastException: $exception,
            attemptDelays: $delays,
        );

        self::assertFalse($result->succeeded);
        self::assertSame(2, $result->attempts);
        self::assertSame('partial', $result->result);
        self::assertSame($exception, $result->lastException);
        self::assertSame([50], $result->attemptDelays);
    }
}
