<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Resilience;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Resilience\BulkheadLimiter;
use Pulsar\Resilience\CircuitBreaker;
use Pulsar\Resilience\Exception\ResilienceException;
use Pulsar\Resilience\ResiliencePolicy;
use Pulsar\Resilience\RetryPolicy;
use RuntimeException;

#[CoversClass(ResiliencePolicy::class)]
final class ResiliencePolicyCoverageTest extends TestCase
{
    #[Test]
    public function timeoutLayerAllowsFastOperations(): void
    {
        $policy = ResiliencePolicy::create()->withTimeout(5000);

        $result = $policy->execute(fn(): string => 'fast');

        self::assertSame('fast', $result);
    }

    #[Test]
    public function retryAndCircuitBreakerComposedCorrectly(): void
    {
        $cb = new CircuitBreaker(name: 'api', failureThreshold: 10, successThreshold: 1, openTimeoutSeconds: 60);
        $retry = new RetryPolicy(maxAttempts: 3, baseDelayMs: 1, maxDelayMs: 10, multiplier: 1.0, jitter: false);

        $callCount = 0;
        $policy = ResiliencePolicy::create()
            ->withRetry($retry)
            ->withCircuitBreaker($cb);

        $result = $policy->execute(function () use (&$callCount): string {
            $callCount++;
            if ($callCount < 2) {
                throw new RuntimeException('transient');
            }
            return 'recovered';
        });

        self::assertSame('recovered', $result);
        self::assertSame(2, $callCount);
    }

    #[Test]
    public function circuitBreakerAndBulkheadComposedCorrectly(): void
    {
        $cb = new CircuitBreaker(name: 'api', failureThreshold: 5, successThreshold: 1, openTimeoutSeconds: 60);
        $bulkhead = new BulkheadLimiter(resource: 'api', maxConcurrent: 10);

        $policy = ResiliencePolicy::create()
            ->withCircuitBreaker($cb)
            ->withBulkhead($bulkhead);

        $result = $policy->execute(fn(): string => 'through both');

        self::assertSame('through both', $result);
    }

    #[Test]
    public function circuitBreakerOpenBlocksBeforeRetry(): void
    {
        $cb = new CircuitBreaker(name: 'blocked', failureThreshold: 1, successThreshold: 1, openTimeoutSeconds: 60);
        $cb->recordFailure();

        $retry = new RetryPolicy(maxAttempts: 3, baseDelayMs: 1, maxDelayMs: 10, multiplier: 1.0, jitter: false);

        $policy = ResiliencePolicy::create()
            ->withRetry($retry)
            ->withCircuitBreaker($cb);

        $this->expectException(ResilienceException::class);
        $this->expectExceptionMessage('Circuit breaker');

        $policy->execute(fn(): string => 'should not run');
    }

    #[Test]
    public function retryOnlyPolicyPassesThroughSuccess(): void
    {
        $retry = new RetryPolicy(maxAttempts: 1, baseDelayMs: 1, maxDelayMs: 10, multiplier: 1.0, jitter: false);
        $policy = ResiliencePolicy::create()->withRetry($retry);

        $result = $policy->execute(fn(): int => 42);

        self::assertSame(42, $result);
    }

    #[Test]
    public function timeoutOnlyPolicyPassesThroughResult(): void
    {
        $policy = ResiliencePolicy::create()->withTimeout(10_000);

        $result = $policy->execute(fn(): array => ['key' => 'val']);

        self::assertSame(['key' => 'val'], $result);
    }

    #[Test]
    public function bulkheadOnlyPolicyPassesThroughResult(): void
    {
        $bulkhead = new BulkheadLimiter(resource: 'test', maxConcurrent: 5);
        $policy = ResiliencePolicy::create()->withBulkhead($bulkhead);

        $result = $policy->execute(fn(): string => 'bulkhead-only');

        self::assertSame('bulkhead-only', $result);
    }

    #[Test]
    public function withMethodsAreImmutable(): void
    {
        $base = ResiliencePolicy::create();

        $retry = new RetryPolicy(maxAttempts: 2, baseDelayMs: 1, maxDelayMs: 10, multiplier: 1.0, jitter: false);
        $cb = new CircuitBreaker(name: 'x', failureThreshold: 3, successThreshold: 1, openTimeoutSeconds: 60);
        $bulkhead = new BulkheadLimiter(resource: 'x', maxConcurrent: 5);

        $a = $base->withRetry($retry);
        $b = $a->withCircuitBreaker($cb);
        $c = $b->withTimeout(5000);
        $d = $c->withBulkhead($bulkhead);

        self::assertNotSame($base, $a);
        self::assertNotSame($a, $b);
        self::assertNotSame($b, $c);
        self::assertNotSame($c, $d);

        // Base policy should still work without any layers
        $result = $base->execute(fn(): string => 'base');
        self::assertSame('base', $result);
    }

    #[Test]
    public function retryExhaustedCarriesLastException(): void
    {
        $retry = new RetryPolicy(maxAttempts: 2, baseDelayMs: 1, maxDelayMs: 10, multiplier: 1.0, jitter: false);
        $policy = ResiliencePolicy::create()->withRetry($retry);

        $this->expectException(ResilienceException::class);
        $this->expectExceptionMessage('Retry exhausted');

        try {
            $policy->execute(static function (): never {
                throw new RuntimeException('persistent');
            });
        } catch (ResilienceException $e) {
            self::assertInstanceOf(RuntimeException::class, $e->getPrevious());
            self::assertSame('persistent', $e->getPrevious()->getMessage());

            throw $e;
        }
    }

    #[Test]
    public function operationReturningNullPreservedThroughAllLayers(): void
    {
        $retry = new RetryPolicy(maxAttempts: 1, baseDelayMs: 1, maxDelayMs: 10, multiplier: 1.0, jitter: false);
        $cb = new CircuitBreaker(name: 'n', failureThreshold: 5, successThreshold: 1, openTimeoutSeconds: 60);
        $bulkhead = new BulkheadLimiter(resource: 'n', maxConcurrent: 10);

        $policy = ResiliencePolicy::create()
            ->withRetry($retry)
            ->withCircuitBreaker($cb)
            ->withTimeout(5000)
            ->withBulkhead($bulkhead);

        $result = $policy->execute(fn(): mixed => null);

        self::assertNull($result);
    }
}
