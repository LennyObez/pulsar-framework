<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Resilience;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Resilience\RetryPolicy;
use Pulsar\Resilience\RetryResult;
use RuntimeException;

#[CoversClass(RetryPolicy::class)]
#[CoversClass(RetryResult::class)]
final class RetryIntegrationTest extends TestCase
{
    #[Test]
    public function retryWithOperationThatFailsThenSucceeds(): void
    {
        $policy = new RetryPolicy(
            maxAttempts: 3,
            baseDelayMs: 1,
            maxDelayMs: 10,
            multiplier: 2.0,
            jitter: false,
        );

        $attemptCount = 0;

        $result = $policy->execute(function () use (&$attemptCount): string {
            $attemptCount++;

            if ($attemptCount < 3) {
                throw new RuntimeException('Temporary failure on attempt ' . $attemptCount);
            }

            return 'success-on-third-try';
        });

        self::assertTrue($result->succeeded);
        self::assertSame(3, $result->attempts);
        self::assertSame('success-on-third-try', $result->result);
        self::assertNull($result->lastException);
        self::assertSame(3, $attemptCount);

        // Two delays: before attempt 2 and before attempt 3
        self::assertCount(2, $result->attemptDelays);
    }

    #[Test]
    public function retryWithOperationThatAlwaysFailsExhaustsAttempts(): void
    {
        $policy = new RetryPolicy(
            maxAttempts: 3,
            baseDelayMs: 1,
            maxDelayMs: 10,
            multiplier: 2.0,
            jitter: false,
        );

        $attemptCount = 0;

        $result = $policy->execute(function () use (&$attemptCount): never {
            $attemptCount++;
            throw new RuntimeException('Persistent failure #' . $attemptCount);
        });

        self::assertFalse($result->succeeded);
        self::assertSame(3, $result->attempts);
        self::assertNull($result->result);
        self::assertNotNull($result->lastException);
        self::assertSame('Persistent failure #3', $result->lastException->getMessage());
        self::assertSame(3, $attemptCount);

        // Two delays: before attempt 2 and before attempt 3
        self::assertCount(2, $result->attemptDelays);
    }

    #[Test]
    public function retryResultContainsCorrectAttemptCountAndDelays(): void
    {
        $policy = new RetryPolicy(
            maxAttempts: 4,
            baseDelayMs: 1,
            maxDelayMs: 10,
            multiplier: 2.0,
            jitter: false,
        );

        $attemptCount = 0;

        // Fail the first 3 attempts, succeed on the 4th
        $result = $policy->execute(function () use (&$attemptCount): string {
            $attemptCount++;

            if ($attemptCount < 4) {
                throw new RuntimeException('fail');
            }

            return 'done';
        });

        self::assertTrue($result->succeeded);
        self::assertSame(4, $result->attempts);
        self::assertSame('done', $result->result);

        // Three delays: before attempts 2, 3, and 4
        self::assertCount(3, $result->attemptDelays);

        // With baseDelay=1, multiplier=2, no jitter:
        // delay for attempt 1->2 = 1 * 2^0 = 1ms
        // delay for attempt 2->3 = 1 * 2^1 = 2ms
        // delay for attempt 3->4 = 1 * 2^2 = 4ms
        self::assertSame(1, $result->attemptDelays[0]);
        self::assertSame(2, $result->attemptDelays[1]);
        self::assertSame(4, $result->attemptDelays[2]);
    }

    #[Test]
    public function retrySucceedsOnFirstAttemptWithNoDelays(): void
    {
        $policy = new RetryPolicy(
            maxAttempts: 3,
            baseDelayMs: 1,
            maxDelayMs: 10,
            multiplier: 2.0,
            jitter: false,
        );

        $result = $policy->execute(static fn(): string => 'immediate-success');

        self::assertTrue($result->succeeded);
        self::assertSame(1, $result->attempts);
        self::assertSame('immediate-success', $result->result);
        self::assertNull($result->lastException);
        self::assertCount(0, $result->attemptDelays);
    }
}
