<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Resilience;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\RetryConfig;
use Pulsar\Resilience\RetryPolicy;
use Pulsar\Resilience\RetryResult;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;

#[CoversClass(RetryPolicy::class)]
#[CoversClass(RetryResult::class)]
final class RetryPolicyCoverageTest extends TestCase
{
    #[Test]
    public function successOnFirstAttemptReturnsImmediately(): void
    {
        $policy = new RetryPolicy(
            maxAttempts: 3,
            baseDelayMs: 100,
            maxDelayMs: 1000,
            multiplier: 2.0,
            jitter: false,
        );

        $result = $policy->execute(fn(): string => 'immediate');

        self::assertTrue($result->succeeded);
        self::assertSame(1, $result->attempts);
        self::assertSame('immediate', $result->result);
        self::assertNull($result->lastException);
        self::assertSame([], $result->attemptDelays);
    }

    #[Test]
    public function retriesUntilSuccess(): void
    {
        $policy = new RetryPolicy(
            maxAttempts: 3,
            baseDelayMs: 1,
            maxDelayMs: 10,
            multiplier: 1.0,
            jitter: false,
        );

        $callCount = 0;
        $result = $policy->execute(function () use (&$callCount): string {
            $callCount++;
            if ($callCount < 3) {
                throw new RuntimeException("attempt {$callCount}");
            }
            return 'recovered';
        });

        self::assertTrue($result->succeeded);
        self::assertSame(3, $result->attempts);
        self::assertSame('recovered', $result->result);
        self::assertNull($result->lastException);
        self::assertCount(2, $result->attemptDelays);
    }

    #[Test]
    public function exhaustedAfterMaxAttempts(): void
    {
        $policy = new RetryPolicy(
            maxAttempts: 2,
            baseDelayMs: 1,
            maxDelayMs: 10,
            multiplier: 1.0,
            jitter: false,
        );

        $result = $policy->execute(fn(): never => throw new RuntimeException('always fails'));

        self::assertFalse($result->succeeded);
        self::assertSame(2, $result->attempts);
        self::assertNull($result->result);
        self::assertInstanceOf(RuntimeException::class, $result->lastException);
        self::assertSame('always fails', $result->lastException->getMessage());
        self::assertCount(1, $result->attemptDelays);
    }

    #[Test]
    public function calculateDelayWithExponentialBackoff(): void
    {
        $policy = new RetryPolicy(
            maxAttempts: 5,
            baseDelayMs: 100,
            maxDelayMs: 10_000,
            multiplier: 2.0,
            jitter: false,
        );

        self::assertSame(100, $policy->calculateDelay(1));  // 100 * 2^0
        self::assertSame(200, $policy->calculateDelay(2));  // 100 * 2^1
        self::assertSame(400, $policy->calculateDelay(3));  // 100 * 2^2
        self::assertSame(800, $policy->calculateDelay(4));  // 100 * 2^3
        self::assertSame(1600, $policy->calculateDelay(5)); // 100 * 2^4
    }

    #[Test]
    public function calculateDelayRespectsCap(): void
    {
        $policy = new RetryPolicy(
            maxAttempts: 5,
            baseDelayMs: 100,
            maxDelayMs: 300,
            multiplier: 2.0,
            jitter: false,
        );

        self::assertSame(100, $policy->calculateDelay(1));
        self::assertSame(200, $policy->calculateDelay(2));
        self::assertSame(300, $policy->calculateDelay(3)); // Capped
        self::assertSame(300, $policy->calculateDelay(4)); // Capped
    }

    #[Test]
    public function calculateDelayWithJitterStaysPositive(): void
    {
        $policy = new RetryPolicy(
            maxAttempts: 5,
            baseDelayMs: 100,
            maxDelayMs: 10_000,
            multiplier: 2.0,
            jitter: true,
            randomizer: new Randomizer(new Mt19937(42)),
        );

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $delay = $policy->calculateDelay($attempt);
            self::assertGreaterThanOrEqual(1, $delay, "Delay for attempt {$attempt} should be >= 1");
        }
    }

    #[Test]
    public function calculateDelayWithZeroJitterRange(): void
    {
        // When baseDelayMs * 0.5 rounds to 0, jitter should not be applied
        $policy = new RetryPolicy(
            maxAttempts: 3,
            baseDelayMs: 1,
            maxDelayMs: 100,
            multiplier: 1.0,
            jitter: true,
        );

        $delay = $policy->calculateDelay(1);
        self::assertSame(1, $delay);
    }

    #[Test]
    public function fromConfigCreatesPolicy(): void
    {
        $config = new RetryConfig(
            maxAttempts: 4,
            baseDelayMs: 200,
            maxDelayMs: 5000,
            multiplier: 3.0,
            jitter: false,
        );

        $policy = RetryPolicy::fromConfig($config);

        // Verify by checking delay calculation (no jitter = exact value)
        self::assertSame(200, $policy->calculateDelay(1));
        self::assertSame(600, $policy->calculateDelay(2)); // 200 * 3^1
    }

    #[Test]
    public function retryResultSuccessFactory(): void
    {
        $result = RetryResult::success('value', 2, [100, 200]);

        self::assertTrue($result->succeeded);
        self::assertSame(2, $result->attempts);
        self::assertSame('value', $result->result);
        self::assertNull($result->lastException);
        self::assertSame([100, 200], $result->attemptDelays);
    }

    #[Test]
    public function retryResultExhaustedFactory(): void
    {
        $exception = new RuntimeException('failed');
        $result = RetryResult::exhausted(3, $exception, [100, 200]);

        self::assertFalse($result->succeeded);
        self::assertSame(3, $result->attempts);
        self::assertNull($result->result);
        self::assertSame($exception, $result->lastException);
        self::assertSame([100, 200], $result->attemptDelays);
    }

    #[Test]
    public function singleAttemptPolicyNoRetry(): void
    {
        $policy = new RetryPolicy(
            maxAttempts: 1,
            baseDelayMs: 1,
            maxDelayMs: 10,
            multiplier: 1.0,
            jitter: false,
        );

        $result = $policy->execute(fn(): never => throw new RuntimeException('once'));

        self::assertFalse($result->succeeded);
        self::assertSame(1, $result->attempts);
        self::assertSame([], $result->attemptDelays);
    }

    #[Test]
    public function retryResultPreservesNullReturnValue(): void
    {
        $result = RetryResult::success(null, 1, []);

        self::assertTrue($result->succeeded);
        self::assertNull($result->result);
    }

    #[Test]
    public function multiplierOfOneMaintainsConstantDelay(): void
    {
        $policy = new RetryPolicy(
            maxAttempts: 5,
            baseDelayMs: 50,
            maxDelayMs: 1000,
            multiplier: 1.0,
            jitter: false,
        );

        self::assertSame(50, $policy->calculateDelay(1));
        self::assertSame(50, $policy->calculateDelay(2));
        self::assertSame(50, $policy->calculateDelay(3));
    }
}
