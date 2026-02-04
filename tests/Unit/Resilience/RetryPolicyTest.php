<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Resilience;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Resilience\RetryPolicy;
use Pulsar\Resilience\RetryResult;
use RuntimeException;

#[CoversClass(RetryPolicy::class)]
#[CoversClass(RetryResult::class)]
final class RetryPolicyTest extends TestCase
{
    #[Test]
    public function succeedsOnFirstAttemptWithoutRetries(): void
    {
        $policy = new RetryPolicy(
            maxAttempts: 3,
            baseDelayMs: 1,
            maxDelayMs: 100,
            multiplier: 2.0,
            jitter: false,
        );

        $result = $policy->execute(fn(): string => 'hello');

        self::assertTrue($result->succeeded);
        self::assertSame(1, $result->attempts);
        self::assertSame('hello', $result->result);
        self::assertNull($result->lastException);
        self::assertSame([], $result->attemptDelays);
    }

    #[Test]
    public function succeedsAfterRetryOnSecondAttempt(): void
    {
        $policy = new RetryPolicy(
            maxAttempts: 3,
            baseDelayMs: 1,
            maxDelayMs: 100,
            multiplier: 2.0,
            jitter: false,
        );

        $callCount = 0;
        $result = $policy->execute(function () use (&$callCount): string {
            $callCount++;

            if ($callCount === 1) {
                throw new RuntimeException('transient failure');
            }

            return 'recovered';
        });

        self::assertTrue($result->succeeded);
        self::assertSame(2, $result->attempts);
        self::assertSame('recovered', $result->result);
        self::assertNull($result->lastException);
        self::assertCount(1, $result->attemptDelays);
    }

    #[Test]
    public function exhaustedAfterAllAttemptsFail(): void
    {
        $policy = new RetryPolicy(
            maxAttempts: 3,
            baseDelayMs: 1,
            maxDelayMs: 100,
            multiplier: 2.0,
            jitter: false,
        );

        $result = $policy->execute(function (): never {
            throw new RuntimeException('persistent failure');
        });

        self::assertFalse($result->succeeded);
        self::assertSame(3, $result->attempts);
        self::assertNull($result->result);
        self::assertInstanceOf(RuntimeException::class, $result->lastException);
        self::assertSame('persistent failure', $result->lastException->getMessage());
        self::assertCount(2, $result->attemptDelays);
    }

    #[Test]
    public function calculateDelayUsesExponentialBackoffCappedAtMaxDelay(): void
    {
        $policy = new RetryPolicy(
            maxAttempts: 5,
            baseDelayMs: 10,
            maxDelayMs: 50,
            multiplier: 2.0,
            jitter: false,
        );

        // attempt 1: 10 * 2^0 = 10
        self::assertSame(10, $policy->calculateDelay(1));

        // attempt 2: 10 * 2^1 = 20
        self::assertSame(20, $policy->calculateDelay(2));

        // attempt 3: 10 * 2^2 = 40
        self::assertSame(40, $policy->calculateDelay(3));

        // attempt 4: 10 * 2^3 = 80, capped at 50
        self::assertSame(50, $policy->calculateDelay(4));

        // attempt 5: 10 * 2^4 = 160, capped at 50
        self::assertSame(50, $policy->calculateDelay(5));
    }

    #[Test]
    public function calculateDelayWithJitterProducesValuesWithinExpectedRange(): void
    {
        $policy = new RetryPolicy(
            maxAttempts: 3,
            baseDelayMs: 100,
            maxDelayMs: 5000,
            multiplier: 2.0,
            jitter: true,
        );

        // attempt 1: base = 100 * 2^0 = 100, jitter range [50, 150]
        $iterations = 100;
        for ($i = 0; $i < $iterations; $i++) {
            $delay = $policy->calculateDelay(1);
            self::assertGreaterThanOrEqual(1, $delay);
            self::assertLessThanOrEqual(150, $delay);
        }

        // attempt 2: base = 100 * 2^1 = 200, jitter range [100, 300]
        for ($i = 0; $i < $iterations; $i++) {
            $delay = $policy->calculateDelay(2);
            self::assertGreaterThanOrEqual(1, $delay);
            self::assertLessThanOrEqual(300, $delay);
        }
    }
}
