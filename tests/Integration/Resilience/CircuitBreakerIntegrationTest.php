<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Resilience;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\CircuitBreakerConfig;
use Pulsar\Resilience\CircuitBreaker;
use Pulsar\Resilience\CircuitBreakerRegistry;
use Pulsar\Resilience\CircuitBreakerState;
use RuntimeException;

#[CoversClass(CircuitBreaker::class)]
#[CoversClass(CircuitBreakerRegistry::class)]
#[CoversClass(CircuitBreakerConfig::class)]
final class CircuitBreakerIntegrationTest extends TestCase
{
    #[Test]
    public function fullLifecycleClosedToOpenToHalfOpenToClosed(): void
    {
        // failureThreshold=3, successThreshold=2, openTimeoutSeconds=0 (immediate transition)
        $breaker = new CircuitBreaker(
            name: 'test-service',
            failureThreshold: 3,
            successThreshold: 2,
            openTimeoutSeconds: 0,
        );

        // Initially closed
        self::assertSame(CircuitBreakerState::Closed, $breaker->state());
        self::assertTrue($breaker->isAvailable());

        // Record 3 failures to trigger open state
        $breaker->recordFailure();
        self::assertSame(CircuitBreakerState::Closed, $breaker->state());
        $failCount = $breaker->failureCount();
        self::assertSame(1, $failCount);

        $breaker->recordFailure();
        self::assertSame(CircuitBreakerState::Closed, $breaker->state());
        $failCount = $breaker->failureCount();
        self::assertSame(2, $failCount);

        $breaker->recordFailure();

        // With openTimeoutSeconds=0, the circuit transitions from Open to HalfOpen
        // immediately on the next state evaluation because elapsed time >= 0
        self::assertSame(CircuitBreakerState::HalfOpen, $breaker->state());
        self::assertTrue($breaker->isAvailable());

        // In HalfOpen, record 2 successes to close the circuit
        $breaker->recordSuccess();
        self::assertSame(CircuitBreakerState::HalfOpen, $breaker->state());

        $breaker->recordSuccess();
        self::assertSame(CircuitBreakerState::Closed, $breaker->state());
        self::assertTrue($breaker->isAvailable());
        $failCount = $breaker->failureCount();
        self::assertSame(0, $failCount);
    }

    #[Test]
    public function executeOpensCircuitAfterRepeatedFailures(): void
    {
        $breaker = new CircuitBreaker(
            name: 'api-gateway',
            failureThreshold: 3,
            successThreshold: 2,
            openTimeoutSeconds: 0,
        );

        // Execute operations that throw
        for ($i = 0; $i < 3; $i++) {
            try {
                $breaker->execute(static function (): never {
                    throw new RuntimeException('service unavailable');
                });
            } catch (RuntimeException) {
                // Expected
            }
        }

        // Circuit is now open, transitions to half-open due to timeout=0
        // In half-open, a failure sends it back to open
        $breaker->recordFailure();

        // After re-opening, evaluateState will transition to half-open again (timeout=0)
        self::assertSame(CircuitBreakerState::HalfOpen, $breaker->state());

        // Successful execution in half-open should start closing
        $result = $breaker->execute(static fn(): string => 'recovered');
        self::assertSame('recovered', $result);

        // One more success needed (successThreshold=2)
        $result2 = $breaker->execute(static fn(): string => 'stable');
        self::assertSame('stable', $result2);

        self::assertSame(CircuitBreakerState::Closed, $breaker->state());
    }

    #[Test]
    public function registryManagesMultipleNamedBreakersIndependently(): void
    {
        $config = new CircuitBreakerConfig(
            failureThreshold: 2,
            successThreshold: 1,
            openTimeoutSeconds: 0,
        );

        $registry = new CircuitBreakerRegistry($config);

        // Get two different breakers
        $dbBreaker = $registry->get('database');
        $apiBreaker = $registry->get('external-api');

        self::assertTrue($registry->has('database'));
        self::assertTrue($registry->has('external-api'));
        self::assertFalse($registry->has('nonexistent'));

        // Same name returns same instance
        self::assertSame($dbBreaker, $registry->get('database'));
        self::assertSame($apiBreaker, $registry->get('external-api'));

        // Trip the database breaker
        $dbBreaker->recordFailure();
        $dbBreaker->recordFailure();

        // database is open (transitions to half-open due to timeout=0)
        self::assertSame(CircuitBreakerState::HalfOpen, $dbBreaker->state());

        // external-api is still closed
        self::assertSame(CircuitBreakerState::Closed, $apiBreaker->state());

        // Reset only the database breaker
        $registry->reset('database');
        self::assertSame(CircuitBreakerState::Closed, $dbBreaker->state());
        self::assertSame(CircuitBreakerState::Closed, $apiBreaker->state());

        // Trip both breakers
        $dbBreaker->recordFailure();
        $dbBreaker->recordFailure();
        $apiBreaker->recordFailure();
        $apiBreaker->recordFailure();

        // Reset all
        $registry->resetAll();
        self::assertSame(CircuitBreakerState::Closed, $dbBreaker->state());
        self::assertSame(CircuitBreakerState::Closed, $apiBreaker->state());

        // Verify all() returns both
        $all = $registry->all();
        self::assertCount(2, $all);
        self::assertArrayHasKey('database', $all);
        self::assertArrayHasKey('external-api', $all);
    }
}
