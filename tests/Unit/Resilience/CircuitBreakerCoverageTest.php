<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Resilience;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\CircuitBreakerConfig;
use Pulsar\Resilience\CircuitBreaker;
use Pulsar\Resilience\CircuitBreakerState;
use RuntimeException;

#[CoversClass(CircuitBreaker::class)]
final class CircuitBreakerCoverageTest extends TestCase
{
    #[Test]
    public function fromConfigCreatesCircuitBreakerWithConfigValues(): void
    {
        $config = new CircuitBreakerConfig(
            failureThreshold: 10,
            successThreshold: 5,
            openTimeoutSeconds: 120,
        );

        $breaker = CircuitBreaker::fromConfig('payment-api', $config);

        self::assertSame('payment-api', $breaker->name());
        self::assertSame(CircuitBreakerState::Closed, $breaker->state());
        self::assertSame(0, $breaker->failureCount());
        self::assertSame(0, $breaker->successCount());
    }

    #[Test]
    public function fromConfigRespectsFailureThreshold(): void
    {
        $config = new CircuitBreakerConfig(failureThreshold: 2, openTimeoutSeconds: 60);
        $breaker = CircuitBreaker::fromConfig('test', $config);

        $breaker->recordFailure();
        self::assertSame(CircuitBreakerState::Closed, $breaker->state());

        $breaker->recordFailure();
        self::assertSame(CircuitBreakerState::Open, $breaker->state());
    }

    #[Test]
    public function executeWithFallbackCallsFallbackWhenCircuitIsOpen(): void
    {
        $breaker = new CircuitBreaker(
            name: 'external-api',
            failureThreshold: 1,
            successThreshold: 2,
            openTimeoutSeconds: 60,
        );

        $breaker->recordFailure();
        self::assertSame(CircuitBreakerState::Open, $breaker->state());

        $result = $breaker->executeWithFallback(
            fn(): string => 'primary',
            fn(): string => 'fallback-value',
        );

        self::assertSame('fallback-value', $result);
    }

    #[Test]
    public function executeWithFallbackRunsPrimaryWhenCircuitIsClosed(): void
    {
        $breaker = new CircuitBreaker(
            name: 'api',
            failureThreshold: 5,
            successThreshold: 2,
            openTimeoutSeconds: 60,
        );

        $result = $breaker->executeWithFallback(
            fn(): string => 'primary-result',
            fn(): string => 'fallback-result',
        );

        self::assertSame('primary-result', $result);
        self::assertSame(0, $breaker->failureCount());
    }

    #[Test]
    public function executeWithFallbackRecordsSuccessOnPrimarySuccess(): void
    {
        $breaker = new CircuitBreaker(
            name: 'api',
            failureThreshold: 1,
            successThreshold: 1,
            openTimeoutSeconds: 0,
        );

        // Open the breaker, then it goes to HalfOpen
        $breaker->recordFailure();
        self::assertSame(CircuitBreakerState::HalfOpen, $breaker->state());

        $result = $breaker->executeWithFallback(
            fn(): string => 'ok',
            fn(): string => 'fallback',
        );

        self::assertSame('ok', $result);
        self::assertSame(CircuitBreakerState::Closed, $breaker->state());
    }

    #[Test]
    public function executeWithFallbackRecordsFailureAndRethrowsOnPrimaryException(): void
    {
        $breaker = new CircuitBreaker(
            name: 'api',
            failureThreshold: 3,
            successThreshold: 2,
            openTimeoutSeconds: 60,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('primary-failed');

        $breaker->executeWithFallback(
            fn(): never => throw new RuntimeException('primary-failed'),
            fn(): string => 'fallback',
        );
    }

    #[Test]
    public function executeWithFallbackRecordsFailureCountOnPrimaryException(): void
    {
        $breaker = new CircuitBreaker(
            name: 'api',
            failureThreshold: 3,
            successThreshold: 2,
            openTimeoutSeconds: 60,
        );

        try {
            $breaker->executeWithFallback(
                fn(): never => throw new RuntimeException('failed'),
                fn(): string => 'fallback',
            );
        } catch (RuntimeException) {
            // expected
        }

        self::assertSame(1, $breaker->failureCount());
    }

    #[Test]
    public function successInClosedStateResetsFailureCount(): void
    {
        $breaker = new CircuitBreaker(
            name: 'test',
            failureThreshold: 5,
            successThreshold: 2,
            openTimeoutSeconds: 60,
        );

        $breaker->recordFailure();
        $breaker->recordFailure();
        self::assertSame(2, $breaker->failureCount());

        $breaker->recordSuccess();
        self::assertSame(0, $breaker->failureCount());
    }

    #[Test]
    public function recordFailureInOpenStateDoesNotChangeState(): void
    {
        $breaker = new CircuitBreaker(
            name: 'test',
            failureThreshold: 1,
            successThreshold: 2,
            openTimeoutSeconds: 60,
        );

        $breaker->recordFailure();
        self::assertSame(CircuitBreakerState::Open, $breaker->state());

        // Additional failures in Open state should not change anything
        $breaker->recordFailure();
        self::assertSame(CircuitBreakerState::Open, $breaker->state());
    }

    #[Test]
    public function recordSuccessInOpenStateDoesNotChangeState(): void
    {
        $breaker = new CircuitBreaker(
            name: 'test',
            failureThreshold: 1,
            successThreshold: 2,
            openTimeoutSeconds: 60,
        );

        $breaker->recordFailure();
        self::assertSame(CircuitBreakerState::Open, $breaker->state());

        // Success in Open state should not transition
        $breaker->recordSuccess();
        self::assertSame(CircuitBreakerState::Open, $breaker->state());
    }

    #[Test]
    public function halfOpenSuccessCountResetsOnTransitionToOpen(): void
    {
        $breaker = new CircuitBreaker(
            name: 'test',
            failureThreshold: 1,
            successThreshold: 3,
            openTimeoutSeconds: 0,
        );

        // Get into HalfOpen
        $breaker->recordFailure();
        self::assertSame(CircuitBreakerState::HalfOpen, $breaker->state());

        // Partial success
        $breaker->recordSuccess();
        $breaker->recordSuccess();
        self::assertSame(2, $breaker->successCount());

        // Failure resets to Open (then immediately HalfOpen with timeout=0)
        $breaker->recordFailure();
        self::assertSame(0, $breaker->successCount());
    }

    #[Test]
    public function executeInHalfOpenRecordsSuccessAndTransitions(): void
    {
        $breaker = new CircuitBreaker(
            name: 'test',
            failureThreshold: 1,
            successThreshold: 1,
            openTimeoutSeconds: 0,
        );

        // Get into HalfOpen
        $breaker->recordFailure();
        self::assertSame(CircuitBreakerState::HalfOpen, $breaker->state());

        $result = $breaker->execute(fn(): string => 'probe-ok');

        self::assertSame('probe-ok', $result);
        self::assertSame(CircuitBreakerState::Closed, $breaker->state());
    }

    #[Test]
    public function executeInHalfOpenRecordsFailureAndReOpens(): void
    {
        $breaker = new CircuitBreaker(
            name: 'test',
            failureThreshold: 1,
            successThreshold: 2,
            openTimeoutSeconds: 0,
        );

        // Get into HalfOpen
        $breaker->recordFailure();
        self::assertSame(CircuitBreakerState::HalfOpen, $breaker->state());

        try {
            $breaker->execute(fn(): never => throw new RuntimeException('probe-fail'));
        } catch (RuntimeException) {
            // expected
        }

        // Should have gone Open -> immediately HalfOpen (timeout=0)
        self::assertSame(0, $breaker->successCount());
    }

    #[Test]
    public function isAvailableTrueInHalfOpenState(): void
    {
        $breaker = new CircuitBreaker(
            name: 'test',
            failureThreshold: 1,
            successThreshold: 2,
            openTimeoutSeconds: 0,
        );

        $breaker->recordFailure();
        // With timeout=0, this is HalfOpen
        self::assertTrue($breaker->isAvailable());
    }

    #[Test]
    public function resetClearsOpenedAtTimestamp(): void
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
        self::assertTrue($breaker->isAvailable());

        // Failure count should be zero after reset
        $breaker->recordFailure();
        self::assertSame(1, $breaker->failureCount());
    }
}
