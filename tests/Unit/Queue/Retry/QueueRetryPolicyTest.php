<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Queue\Retry;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\QueueConfig;
use Pulsar\Queue\Retry\QueueRetryPolicy;
use Pulsar\Queue\Retry\RetryDecision;
use RuntimeException;

#[CoversClass(QueueRetryPolicy::class)]
final class QueueRetryPolicyTest extends TestCase
{
    #[Test]
    public function it_returns_retry_when_under_max_attempts(): void
    {
        $policy = new QueueRetryPolicy(
            maxAttempts: 3,
            baseDelayMs: 1000,
            maxDelayMs: 60000,
            multiplier: 2.0,
        );

        self::assertSame(RetryDecision::Retry, $policy->shouldRetry(1));
        self::assertSame(RetryDecision::Retry, $policy->shouldRetry(2));
    }

    #[Test]
    public function it_returns_dead_letter_at_max_attempts(): void
    {
        $policy = new QueueRetryPolicy(
            maxAttempts: 3,
            baseDelayMs: 1000,
            maxDelayMs: 60000,
            multiplier: 2.0,
        );

        self::assertSame(RetryDecision::DeadLetter, $policy->shouldRetry(3));
    }

    #[Test]
    public function it_returns_dead_letter_beyond_max_attempts(): void
    {
        $policy = new QueueRetryPolicy(
            maxAttempts: 3,
            baseDelayMs: 1000,
            maxDelayMs: 60000,
            multiplier: 2.0,
        );

        self::assertSame(RetryDecision::DeadLetter, $policy->shouldRetry(5));
    }

    #[Test]
    public function it_accepts_exception_parameter_without_affecting_decision(): void
    {
        $policy = new QueueRetryPolicy(
            maxAttempts: 3,
            baseDelayMs: 1000,
            maxDelayMs: 60000,
            multiplier: 2.0,
        );

        $exception = new RuntimeException('Test error');

        self::assertSame(RetryDecision::Retry, $policy->shouldRetry(1, $exception));
        self::assertSame(RetryDecision::DeadLetter, $policy->shouldRetry(3, $exception));
    }

    #[Test]
    public function it_calculates_exponential_backoff_delay(): void
    {
        $policy = new QueueRetryPolicy(
            maxAttempts: 5,
            baseDelayMs: 1000,
            maxDelayMs: 60000,
            multiplier: 2.0,
        );

        // attempt 1: 1000 * 2^0 = 1000
        self::assertSame(1000, $policy->getDelay(1));

        // attempt 2: 1000 * 2^1 = 2000
        self::assertSame(2000, $policy->getDelay(2));

        // attempt 3: 1000 * 2^2 = 4000
        self::assertSame(4000, $policy->getDelay(3));

        // attempt 4: 1000 * 2^3 = 8000
        self::assertSame(8000, $policy->getDelay(4));
    }

    #[Test]
    public function it_caps_delay_at_max_delay(): void
    {
        $policy = new QueueRetryPolicy(
            maxAttempts: 10,
            baseDelayMs: 1000,
            maxDelayMs: 5000,
            multiplier: 2.0,
        );

        // attempt 4: 1000 * 2^3 = 8000, capped to 5000
        self::assertSame(5000, $policy->getDelay(4));

        // attempt 10: would be huge, capped to 5000
        self::assertSame(5000, $policy->getDelay(10));
    }

    #[Test]
    public function it_handles_multiplier_of_one(): void
    {
        $policy = new QueueRetryPolicy(
            maxAttempts: 5,
            baseDelayMs: 2000,
            maxDelayMs: 60000,
            multiplier: 1.0,
        );

        // All attempts have the same delay: 2000 * 1^n = 2000
        self::assertSame(2000, $policy->getDelay(1));
        self::assertSame(2000, $policy->getDelay(2));
        self::assertSame(2000, $policy->getDelay(5));
    }

    #[Test]
    public function it_handles_fractional_multiplier(): void
    {
        $policy = new QueueRetryPolicy(
            maxAttempts: 5,
            baseDelayMs: 1000,
            maxDelayMs: 60000,
            multiplier: 1.5,
        );

        // attempt 1: 1000 * 1.5^0 = 1000
        self::assertSame(1000, $policy->getDelay(1));

        // attempt 2: 1000 * 1.5^1 = 1500
        self::assertSame(1500, $policy->getDelay(2));

        // attempt 3: 1000 * 1.5^2 = 2250
        self::assertSame(2250, $policy->getDelay(3));
    }

    #[Test]
    public function it_creates_from_queue_config(): void
    {
        $config = new QueueConfig(
            retryMaxAttempts: 5,
            retryBaseDelayMs: 2000,
            retryMaxDelayMs: 120000,
            retryMultiplier: 3.0,
        );

        $policy = QueueRetryPolicy::fromConfig($config);

        self::assertSame(RetryDecision::Retry, $policy->shouldRetry(1));
        self::assertSame(RetryDecision::Retry, $policy->shouldRetry(4));
        self::assertSame(RetryDecision::DeadLetter, $policy->shouldRetry(5));

        // attempt 1: 2000 * 3^0 = 2000
        self::assertSame(2000, $policy->getDelay(1));
        // attempt 2: 2000 * 3^1 = 6000
        self::assertSame(6000, $policy->getDelay(2));
    }

    #[Test]
    public function it_creates_from_default_queue_config(): void
    {
        $config = new QueueConfig();
        $policy = QueueRetryPolicy::fromConfig($config);

        // Default: maxAttempts=3, baseDelayMs=1000, maxDelayMs=60000, multiplier=2.0
        self::assertSame(RetryDecision::Retry, $policy->shouldRetry(1));
        self::assertSame(RetryDecision::Retry, $policy->shouldRetry(2));
        self::assertSame(RetryDecision::DeadLetter, $policy->shouldRetry(3));

        self::assertSame(1000, $policy->getDelay(1));
        self::assertSame(2000, $policy->getDelay(2));
    }

    #[Test]
    public function it_dead_letters_with_single_max_attempt(): void
    {
        $policy = new QueueRetryPolicy(
            maxAttempts: 1,
            baseDelayMs: 1000,
            maxDelayMs: 60000,
            multiplier: 2.0,
        );

        self::assertSame(RetryDecision::DeadLetter, $policy->shouldRetry(1));
    }
}
