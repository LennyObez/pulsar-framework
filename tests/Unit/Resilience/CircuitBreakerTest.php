<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Resilience;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Resilience\CircuitBreaker;
use Pulsar\Resilience\CircuitBreakerState;
use Pulsar\Resilience\Exception\ResilienceException;
use RuntimeException;

#[CoversClass(CircuitBreaker::class)]
final class CircuitBreakerTest extends TestCase
{
    #[Test]
    public function startsInClosedState(): void
    {
        $breaker = new CircuitBreaker(
            name: 'test',
            failureThreshold: 3,
            successThreshold: 2,
            openTimeoutSeconds: 60,
        );

        self::assertSame(CircuitBreakerState::Closed, $breaker->state());
    }

    #[Test]
    public function staysClosedBelowFailureThreshold(): void
    {
        $breaker = new CircuitBreaker(
            name: 'test',
            failureThreshold: 3,
            successThreshold: 2,
            openTimeoutSeconds: 60,
        );

        $breaker->recordFailure();
        $breaker->recordFailure();

        self::assertSame(CircuitBreakerState::Closed, $breaker->state());
        self::assertSame(2, $breaker->failureCount());
    }

    #[Test]
    public function transitionsToOpenAtFailureThreshold(): void
    {
        $breaker = new CircuitBreaker(
            name: 'test',
            failureThreshold: 3,
            successThreshold: 2,
            openTimeoutSeconds: 60,
        );

        $breaker->recordFailure();
        $breaker->recordFailure();
        $breaker->recordFailure();

        self::assertSame(CircuitBreakerState::Open, $breaker->state());
    }

    #[Test]
    public function openRejectsCallsWithCircuitOpenException(): void
    {
        $breaker = new CircuitBreaker(
            name: 'my-service',
            failureThreshold: 1,
            successThreshold: 1,
            openTimeoutSeconds: 60,
        );

        $breaker->recordFailure();

        self::assertSame(CircuitBreakerState::Open, $breaker->state());

        $this->expectException(ResilienceException::class);
        $this->expectExceptionMessage('Circuit breaker "my-service" is open');

        $breaker->execute(fn(): string => 'should not run');
    }

    #[Test]
    public function transitionsFromOpenToHalfOpenAfterTimeout(): void
    {
        $breaker = new CircuitBreaker(
            name: 'test',
            failureThreshold: 1,
            successThreshold: 2,
            openTimeoutSeconds: 0,
        );

        $breaker->recordFailure();

        // With openTimeoutSeconds=0, the timeout has already elapsed,
        // so state() evaluates the transition and returns HalfOpen immediately.
        self::assertSame(CircuitBreakerState::HalfOpen, $breaker->state());
    }

    #[Test]
    public function transitionsFromHalfOpenToClosedAfterSuccessThreshold(): void
    {
        $breaker = new CircuitBreaker(
            name: 'test',
            failureThreshold: 1,
            successThreshold: 2,
            openTimeoutSeconds: 0,
        );

        // Open the breaker, then it immediately transitions to HalfOpen (timeout=0)
        $breaker->recordFailure();
        self::assertSame(CircuitBreakerState::HalfOpen, $breaker->state());

        // Record successes to meet the threshold
        $breaker->recordSuccess();
        self::assertSame(CircuitBreakerState::HalfOpen, $breaker->state());

        $breaker->recordSuccess();
        self::assertSame(CircuitBreakerState::Closed, $breaker->state());
    }

    #[Test]
    public function transitionsFromHalfOpenToOpenOnSingleFailure(): void
    {
        // Use a long timeout so we can observe the Open state after HalfOpen fails.
        $breaker = new CircuitBreaker(
            name: 'test',
            failureThreshold: 1,
            successThreshold: 2,
            openTimeoutSeconds: 60,
        );

        // Open the breaker with a failure (long timeout means it stays Open)
        $breaker->recordFailure();
        self::assertSame(CircuitBreakerState::Open, $breaker->state());

        // Manually reset and use timeout=0 breaker to get into HalfOpen
        $halfOpenBreaker = new CircuitBreaker(
            name: 'test-half',
            failureThreshold: 1,
            successThreshold: 2,
            openTimeoutSeconds: 0,
        );

        // Get into HalfOpen state
        $halfOpenBreaker->recordFailure();
        self::assertSame(CircuitBreakerState::HalfOpen, $halfOpenBreaker->state());

        // Record one success (not enough to close, need 2)
        $halfOpenBreaker->recordSuccess();
        self::assertSame(CircuitBreakerState::HalfOpen, $halfOpenBreaker->state());
        self::assertSame(1, $halfOpenBreaker->successCount());

        // A failure in HalfOpen transitions to Open, resetting success count.
        // With timeout=0 it immediately goes back to HalfOpen, but the success
        // count is reset, proving the Open transition occurred.
        $halfOpenBreaker->recordFailure();
        self::assertSame(0, $halfOpenBreaker->successCount());
    }

    #[Test]
    public function resetReturnsToClosed(): void
    {
        $breaker = new CircuitBreaker(
            name: 'test',
            failureThreshold: 1,
            successThreshold: 2,
            openTimeoutSeconds: 60,
        );

        $breaker->recordFailure();
        self::assertSame(CircuitBreakerState::Open, $breaker->state());

        $breaker->reset();

        self::assertSame(CircuitBreakerState::Closed, $breaker->state());
        self::assertSame(0, $breaker->failureCount());
        self::assertSame(0, $breaker->successCount());
    }

    #[Test]
    public function executeRecordsSuccess(): void
    {
        $breaker = new CircuitBreaker(
            name: 'test',
            failureThreshold: 3,
            successThreshold: 2,
            openTimeoutSeconds: 60,
        );

        $result = $breaker->execute(fn(): string => 'ok');

        self::assertSame('ok', $result);
        self::assertSame(CircuitBreakerState::Closed, $breaker->state());
        self::assertSame(0, $breaker->failureCount());
    }

    #[Test]
    public function executeRecordsFailureAndRethrows(): void
    {
        $breaker = new CircuitBreaker(
            name: 'test',
            failureThreshold: 3,
            successThreshold: 2,
            openTimeoutSeconds: 60,
        );

        try {
            $breaker->execute(function (): string {
                throw new RuntimeException('operation failed');
            });
            self::fail('Expected RuntimeException to be thrown'); // @phpstan-ignore deadCode.unreachable
        } catch (RuntimeException $e) {
            self::assertSame('operation failed', $e->getMessage());
        }

        self::assertSame(1, $breaker->failureCount());
    }

    #[Test]
    public function isAvailableReflectsCurrentState(): void
    {
        $breaker = new CircuitBreaker(
            name: 'test',
            failureThreshold: 1,
            successThreshold: 1,
            openTimeoutSeconds: 60,
        );

        self::assertTrue($breaker->isAvailable());

        $breaker->recordFailure();

        self::assertFalse($breaker->isAvailable());
    }

    #[Test]
    public function nameReturnsCircuitBreakerName(): void
    {
        $breaker = new CircuitBreaker(
            name: 'payment-gateway',
            failureThreshold: 3,
            successThreshold: 2,
            openTimeoutSeconds: 30,
        );

        self::assertSame('payment-gateway', $breaker->name());
    }
}
