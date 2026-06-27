<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Resilience;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Pulsar\Resilience\CircuitBreaker;
use Pulsar\Resilience\CircuitBreakerState;
use Pulsar\Resilience\Exception\ResilienceException;
use RuntimeException;
use Stringable;

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

    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $loggedRecords = [];

    #[Test]
    public function logsAtWarningWhenTheCircuitOpens(): void
    {
        $breaker = new CircuitBreaker(
            name: 'orders',
            failureThreshold: 2,
            successThreshold: 1,
            openTimeoutSeconds: 60,
            logger: $this->makeLogger(),
        );

        $breaker->recordFailure();
        self::assertCount(0, $this->loggedRecords);

        $breaker->recordFailure();

        $toOpen = $this->recordsTo(CircuitBreakerState::Open);
        self::assertCount(1, $toOpen);
        $record = $toOpen[0];
        self::assertSame(LogLevel::WARNING, $record['level']);
        self::assertSame('orders', $this->contextString($record, 'circuit'));
        self::assertSame(2, $this->contextInt($record, 'failure_count'));
    }

    #[Test]
    public function logsHalfOpenAndCloseTransitionsAtInfo(): void
    {
        $breaker = new CircuitBreaker(
            name: 'orders',
            failureThreshold: 1,
            successThreshold: 1,
            openTimeoutSeconds: 0,
            logger: $this->makeLogger(),
        );

        // Open (WARNING).
        $breaker->recordFailure();
        // Reading the state evaluates the Open->HalfOpen transition (timeout 0).
        self::assertSame(CircuitBreakerState::HalfOpen, $breaker->state());
        // One success in half-open closes the circuit.
        $breaker->recordSuccess();
        self::assertSame(CircuitBreakerState::Closed, $breaker->state());

        self::assertCount(1, $this->recordsTo(CircuitBreakerState::Open));

        $halfOpen = $this->recordsTo(CircuitBreakerState::HalfOpen);
        self::assertCount(1, $halfOpen);
        self::assertSame(LogLevel::INFO, $halfOpen[0]['level']);

        $closed = $this->recordsTo(CircuitBreakerState::Closed);
        self::assertCount(1, $closed);
        self::assertSame(LogLevel::INFO, $closed[0]['level']);
    }

    #[Test]
    public function doesNotLogWhenNoLoggerProvided(): void
    {
        $breaker = new CircuitBreaker(
            name: 'orders',
            failureThreshold: 1,
            successThreshold: 1,
            openTimeoutSeconds: 60,
        );

        $breaker->recordFailure();

        self::assertSame(CircuitBreakerState::Open, $breaker->state());
        self::assertCount(0, $this->loggedRecords);
    }

    /**
     * @return list<array{level: string, message: string, context: array<string, mixed>}>
     */
    private function recordsTo(CircuitBreakerState $state): array
    {
        $matches = [];

        foreach ($this->loggedRecords as $record) {
            if (($record['context']['to'] ?? null) === $state->value) {
                $matches[] = $record;
            }
        }

        return $matches;
    }

    /**
     * @param array{level: string, message: string, context: array<string, mixed>} $record
     */
    private function contextString(array $record, string $key): string
    {
        $value = $record['context'][$key] ?? null;
        self::assertIsString($value);

        return $value;
    }

    /**
     * @param array{level: string, message: string, context: array<string, mixed>} $record
     */
    private function contextInt(array $record, string $key): int
    {
        $value = $record['context'][$key] ?? null;
        self::assertIsInt($value);

        return $value;
    }

    private function makeLogger(): LoggerInterface
    {
        $test = $this;

        return new class ($test) implements LoggerInterface {
            public function __construct(private readonly CircuitBreakerTest $test) {}

            /** @param array<mixed> $context */
            private function record(string $level, string $message, array $context): void
            {
                /** @var array<string, mixed> $typedContext */
                $typedContext = $context;
                $this->test->loggedRecords[] = ['level' => $level, 'message' => $message, 'context' => $typedContext];
            }

            public function emergency(Stringable|string $message, array $context = []): void
            {
                $this->record(LogLevel::EMERGENCY, (string) $message, $context);
            }

            public function alert(Stringable|string $message, array $context = []): void
            {
                $this->record(LogLevel::ALERT, (string) $message, $context);
            }

            public function critical(Stringable|string $message, array $context = []): void
            {
                $this->record(LogLevel::CRITICAL, (string) $message, $context);
            }

            public function error(Stringable|string $message, array $context = []): void
            {
                $this->record(LogLevel::ERROR, (string) $message, $context);
            }

            public function warning(Stringable|string $message, array $context = []): void
            {
                $this->record(LogLevel::WARNING, (string) $message, $context);
            }

            public function notice(Stringable|string $message, array $context = []): void
            {
                $this->record(LogLevel::NOTICE, (string) $message, $context);
            }

            public function info(Stringable|string $message, array $context = []): void
            {
                $this->record(LogLevel::INFO, (string) $message, $context);
            }

            public function debug(Stringable|string $message, array $context = []): void
            {
                $this->record(LogLevel::DEBUG, (string) $message, $context);
            }

            /** @param array<mixed> $context */
            public function log(mixed $level, Stringable|string $message, array $context = []): void
            {
                /** @var string $levelString */
                $levelString = $level;
                $this->record($levelString, (string) $message, $context);
            }
        };
    }
}
