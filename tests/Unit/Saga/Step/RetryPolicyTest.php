<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Saga\Step;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Saga\Step\BackoffStrategy;
use Pulsar\Saga\Step\RetryPolicy;

#[CoversClass(RetryPolicy::class)]
final class RetryPolicyTest extends TestCase
{
    #[Test]
    public function test_default_values(): void
    {
        $policy = new RetryPolicy();

        self::assertSame(1, $policy->maxAttempts);
        self::assertSame(BackoffStrategy::Exponential, $policy->backoff);
        self::assertSame(100, $policy->initialDelayMs);
    }

    #[Test]
    public function test_custom_values(): void
    {
        $policy = new RetryPolicy(
            maxAttempts: 5,
            backoff: BackoffStrategy::Linear,
            initialDelayMs: 200,
        );

        self::assertSame(5, $policy->maxAttempts);
        self::assertSame(BackoffStrategy::Linear, $policy->backoff);
        self::assertSame(200, $policy->initialDelayMs);
    }

    #[Test]
    public function test_delayForAttempt_first_attempt_returns_zero(): void
    {
        $policy = new RetryPolicy(maxAttempts: 3, initialDelayMs: 100);

        self::assertSame(0, $policy->delayForAttempt(1));
    }

    /**
     * @param int<1, max> $attempt
     */
    #[Test]
    #[DataProvider('exponentialBackoffProvider')]
    public function test_delayForAttempt_exponential_backoff(int $attempt, int $expectedDelay): void
    {
        $policy = new RetryPolicy(
            maxAttempts: 10,
            backoff: BackoffStrategy::Exponential,
            initialDelayMs: 100,
        );

        self::assertSame($expectedDelay, $policy->delayForAttempt($attempt));
    }

    /**
     * @return iterable<string, array{int<1, max>, int}>
     */
    public static function exponentialBackoffProvider(): iterable
    {
        yield 'attempt 1 (no delay)' => [1, 0];
        yield 'attempt 2 (1st retry = 100ms)' => [2, 100];
        yield 'attempt 3 (2nd retry = 200ms)' => [3, 200];
        yield 'attempt 4 (3rd retry = 400ms)' => [4, 400];
        yield 'attempt 5 (4th retry = 800ms)' => [5, 800];
    }

    /**
     * @param int<1, max> $attempt
     */
    #[Test]
    #[DataProvider('linearBackoffProvider')]
    public function test_delayForAttempt_linear_backoff(int $attempt, int $expectedDelay): void
    {
        $policy = new RetryPolicy(
            maxAttempts: 10,
            backoff: BackoffStrategy::Linear,
            initialDelayMs: 100,
        );

        self::assertSame($expectedDelay, $policy->delayForAttempt($attempt));
    }

    /**
     * @return iterable<string, array{int<1, max>, int}>
     */
    public static function linearBackoffProvider(): iterable
    {
        yield 'attempt 1 (no delay)' => [1, 0];
        yield 'attempt 2 (1st retry = 100ms)' => [2, 100];
        yield 'attempt 3 (2nd retry = 200ms)' => [3, 200];
        yield 'attempt 4 (3rd retry = 300ms)' => [4, 300];
        yield 'attempt 5 (4th retry = 400ms)' => [5, 400];
    }

    #[Test]
    public function test_delayForAttempt_zero_initial_delay(): void
    {
        $policy = new RetryPolicy(
            maxAttempts: 3,
            backoff: BackoffStrategy::Exponential,
            initialDelayMs: 0,
        );

        self::assertSame(0, $policy->delayForAttempt(2));
        self::assertSame(0, $policy->delayForAttempt(3));
    }
}
