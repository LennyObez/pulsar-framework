<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Failover;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Exception\DatabaseException;
use Pulsar\Database\Failover\FailoverConfig;
use Pulsar\Database\Failover\FailoverManager;
use Pulsar\Database\Failover\FailoverStrategyInterface;
use Pulsar\Database\Health\ConnectionHealthCheckerInterface;
use Pulsar\Observability\Metrics\MetricRegistry;
use Pulsar\Resilience\CircuitBreaker;
use Pulsar\Resilience\CircuitBreakerState;

#[CoversClass(FailoverManager::class)]
final class FailoverManagerTest extends TestCase
{
    private ConnectionInterface&Stub $connection;
    private ConnectionHealthCheckerInterface&Stub $healthChecker;
    private FailoverStrategyInterface&Stub $strategy;
    private CircuitBreaker $circuitBreaker;
    private MetricRegistry $metrics;

    protected function setUp(): void
    {
        $this->connection = $this->createStub(ConnectionInterface::class);
        $this->healthChecker = $this->createStub(ConnectionHealthCheckerInterface::class);
        $this->strategy = $this->createStub(FailoverStrategyInterface::class);
        $this->circuitBreaker = new CircuitBreaker(
            name: 'db-primary',
            failureThreshold: 3,
            successThreshold: 1,
            openTimeoutSeconds: 30,
        );
        $this->metrics = new MetricRegistry();
    }

    private function createManager(
        ?FailoverConfig $config = null,
        string $primaryEndpoint = '10.0.0.1',
        ?callable $connectionFactory = null,
    ): FailoverManager {
        return new FailoverManager(
            primaryConnection: $this->connection,
            healthChecker: $this->healthChecker,
            strategy: $this->strategy,
            circuitBreaker: $this->circuitBreaker,
            config: $config ?? new FailoverConfig(enabled: true),
            metrics: $this->metrics,
            primaryEndpoint: $primaryEndpoint,
            connectionFactory: $connectionFactory,
        );
    }

    #[Test]
    public function checkPrimaryReturnsTrueWhenHealthy(): void
    {
        $this->healthChecker->method('isHealthy')->willReturn(true);

        $manager = $this->createManager();

        self::assertTrue($manager->checkPrimary());
    }

    #[Test]
    public function checkPrimaryReturnsFalseWhenUnhealthy(): void
    {
        $this->healthChecker->method('isHealthy')->willReturn(false);

        $manager = $this->createManager();

        self::assertFalse($manager->checkPrimary());
    }

    #[Test]
    public function executeFailoverSwitchesPrimary(): void
    {
        $this->strategy->method('resolveTarget')->willReturn('10.0.0.2');
        $this->strategy->method('name')->willReturn('test');

        $manager = $this->createManager();

        self::assertSame('10.0.0.1', $manager->getCurrentPrimary());

        $result = $manager->executeFailover();

        self::assertTrue($result);
        self::assertSame('10.0.0.2', $manager->getCurrentPrimary());
    }

    #[Test]
    public function circuitOpensAfterThresholdFailures(): void
    {
        $this->healthChecker->method('isHealthy')->willReturn(false);

        $manager = $this->createManager();

        // 3 failures = threshold
        $manager->checkPrimary();
        $manager->checkPrimary();
        $manager->checkPrimary();

        self::assertTrue($manager->isCircuitOpen());
    }

    #[Test]
    public function circuitBreakerRejectsWhenOpen(): void
    {
        $this->healthChecker->method('isHealthy')->willReturn(false);

        $manager = $this->createManager();

        // Open the circuit
        $manager->checkPrimary();
        $manager->checkPrimary();
        $manager->checkPrimary();

        self::assertTrue($manager->isCircuitOpen());
        self::assertSame(CircuitBreakerState::Open, $this->circuitBreaker->state());
    }

    #[Test]
    public function circuitClosesOnSuccessfulCheck(): void
    {
        $this->healthChecker
            ->method('isHealthy')
            ->willReturnOnConsecutiveCalls(false, false, false, true);

        // Use a circuit breaker with instant timeout so it transitions to half-open
        // immediately, allowing the next success to close it
        $this->circuitBreaker = new CircuitBreaker(
            name: 'db-primary',
            failureThreshold: 3,
            successThreshold: 1,
            openTimeoutSeconds: 0,
        );

        $manager = $this->createManager();

        // Open circuit with 3 failures
        $manager->checkPrimary();
        $manager->checkPrimary();
        $manager->checkPrimary();

        // With openTimeoutSeconds=0, the circuit immediately transitions to
        // half-open on the next state evaluation, so a successful check closes it
        self::assertSame(3, $this->circuitBreaker->failureCount());

        // Success should transition: Open -> HalfOpen -> Closed
        $manager->checkPrimary();

        self::assertFalse($manager->isCircuitOpen());
        self::assertSame(CircuitBreakerState::Closed, $this->circuitBreaker->state());
    }

    #[Test]
    public function failoverEmitsTelemetryCounters(): void
    {
        $this->healthChecker->method('isHealthy')->willReturn(false);
        $this->strategy->method('resolveTarget')->willReturn('10.0.0.2');
        $this->strategy->method('name')->willReturn('test');

        $manager = $this->createManager();

        // Health check failure emits counter
        $manager->checkPrimary();

        $primaryUnavailable = $this->metrics->counter('db.primary_unavailable');
        self::assertSame(1.0, $primaryUnavailable->value());

        // Failover emits counter
        $manager->executeFailover();

        $failoverExecuted = $this->metrics->counter('db.failover_executed');
        self::assertSame(1.0, $failoverExecuted->value());
    }

    #[Test]
    public function complianceEventEmittedInRegulatedMode(): void
    {
        $this->strategy->method('resolveTarget')->willReturn('10.0.0.2');
        $this->strategy->method('name')->willReturn('test');

        $config = new FailoverConfig(
            enabled: true,
            complianceEventsEnabled: true,
        );

        $manager = $this->createManager(config: $config);

        $manager->executeFailover();

        $events = $manager->events();
        self::assertCount(1, $events);

        $event = $events[0];
        self::assertSame('10.0.0.1', $event->sourceEndpoint);
        self::assertSame('10.0.0.2', $event->targetEndpoint);
        self::assertSame('Primary health check failure', $event->reason);
        self::assertGreaterThanOrEqual(0.0, $event->durationMs);
        self::assertNotEmpty($event->correlationId);
    }

    #[Test]
    public function failoverThrowsWhenNoTargetResolved(): void
    {
        $this->strategy->method('resolveTarget')->willReturn(null);
        $this->strategy->method('name')->willReturn('test');

        $manager = $this->createManager();

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessageIsOrContains('Strategy "test" could not resolve a failover target');

        $manager->executeFailover();
    }

    #[Test]
    public function complianceEventsNotEmittedWhenDisabled(): void
    {
        $this->strategy->method('resolveTarget')->willReturn('10.0.0.2');
        $this->strategy->method('name')->willReturn('test');

        $config = new FailoverConfig(
            enabled: true,
            complianceEventsEnabled: false,
        );

        $manager = $this->createManager(config: $config);
        $manager->executeFailover();

        self::assertCount(0, $manager->events());
    }

    #[Test]
    public function failoverReconnectsWhenFactoryProvided(): void
    {
        $newConnection = $this->createStub(ConnectionInterface::class);

        $this->strategy->method('resolveTarget')->willReturn('10.0.0.2');
        $this->strategy->method('name')->willReturn('test');

        $factoryCallCount = 0;
        $factory = function () use ($newConnection, &$factoryCallCount): ConnectionInterface {
            $factoryCallCount++;
            return $newConnection;
        };

        $manager = $this->createManager(connectionFactory: $factory);

        $manager->executeFailover();

        self::assertSame(1, $factoryCallCount);
        self::assertSame($newConnection, $manager->getConnection());
    }

    #[Test]
    public function failoverWithoutFactoryPreservesOriginalConnection(): void
    {
        $this->strategy->method('resolveTarget')->willReturn('10.0.0.2');
        $this->strategy->method('name')->willReturn('test');

        $manager = $this->createManager();

        $manager->executeFailover();

        // Without a factory, getConnection returns the original (now stale) connection
        self::assertSame($this->connection, $manager->getConnection());
    }

    #[Test]
    public function failoverDisconnectsOldConnectionBeforeReconnecting(): void
    {
        $oldConnection = $this->createMock(ConnectionInterface::class);
        $oldConnection->expects(self::once())->method('disconnect');

        $newConnection = $this->createStub(ConnectionInterface::class);

        $this->strategy->method('resolveTarget')->willReturn('10.0.0.2');
        $this->strategy->method('name')->willReturn('test');

        $manager = new FailoverManager(
            primaryConnection: $oldConnection,
            healthChecker: $this->healthChecker,
            strategy: $this->strategy,
            circuitBreaker: $this->circuitBreaker,
            config: new FailoverConfig(enabled: true),
            metrics: $this->metrics,
            primaryEndpoint: '10.0.0.1',
            connectionFactory: static fn(): ConnectionInterface => $newConnection,
        );

        $manager->executeFailover();

        self::assertSame($newConnection, $manager->getConnection());
    }

    #[Test]
    public function getConnectionReturnsCurrentPrimaryConnection(): void
    {
        $manager = $this->createManager();

        self::assertSame($this->connection, $manager->getConnection());
    }
}
