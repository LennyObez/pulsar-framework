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
final class ResiliencePolicyTest extends TestCase
{
    #[Test]
    public function executeWithNoLayersRunsOperationDirectly(): void
    {
        $policy = ResiliencePolicy::create();

        $result = $policy->execute(fn(): string => 'bare execution');

        self::assertSame('bare execution', $result);
    }

    #[Test]
    public function createReturnsNewInstance(): void
    {
        $policy = ResiliencePolicy::create();

        self::assertInstanceOf(ResiliencePolicy::class, $policy);
    }

    #[Test]
    public function withRetryReturnsNewImmutableInstance(): void
    {
        $original = ResiliencePolicy::create();
        $retry = new RetryPolicy(maxAttempts: 3, baseDelayMs: 10, maxDelayMs: 100, multiplier: 2.0, jitter: false);
        $withRetry = $original->withRetry($retry);

        self::assertNotSame($original, $withRetry);
    }

    #[Test]
    public function withCircuitBreakerReturnsNewInstance(): void
    {
        $original = ResiliencePolicy::create();
        $cb = new CircuitBreaker(name: 'test', failureThreshold: 3, successThreshold: 1, openTimeoutSeconds: 60);
        $withCb = $original->withCircuitBreaker($cb);

        self::assertNotSame($original, $withCb);
    }

    #[Test]
    public function withTimeoutReturnsNewInstance(): void
    {
        $original = ResiliencePolicy::create();
        $withTimeout = $original->withTimeout(5000);

        self::assertNotSame($original, $withTimeout);
    }

    #[Test]
    public function withBulkheadReturnsNewInstance(): void
    {
        $original = ResiliencePolicy::create();
        $bulkhead = new BulkheadLimiter(resource: 'test', maxConcurrent: 5);
        $withBulkhead = $original->withBulkhead($bulkhead);

        self::assertNotSame($original, $withBulkhead);
    }

    #[Test]
    public function retryLayerRetriesOnFailure(): void
    {
        $retry = new RetryPolicy(maxAttempts: 3, baseDelayMs: 1, maxDelayMs: 10, multiplier: 1.0, jitter: false);
        $policy = ResiliencePolicy::create()->withRetry($retry);

        $callCount = 0;
        $result = $policy->execute(function () use (&$callCount): string {
            $callCount++;
            if ($callCount < 3) {
                throw new RuntimeException('transient failure');
            }
            return 'recovered';
        });

        self::assertSame('recovered', $result);
        self::assertSame(3, $callCount);
    }

    #[Test]
    public function retryLayerThrowsWhenExhausted(): void
    {
        $retry = new RetryPolicy(maxAttempts: 2, baseDelayMs: 1, maxDelayMs: 10, multiplier: 1.0, jitter: false);
        $policy = ResiliencePolicy::create()->withRetry($retry);

        $this->expectException(ResilienceException::class);
        $this->expectExceptionMessage('Retry exhausted');

        $policy->execute(function (): never {
            throw new RuntimeException('always fails');
        });
    }

    #[Test]
    public function circuitBreakerLayerRejectsWhenOpen(): void
    {
        $cb = new CircuitBreaker(name: 'api', failureThreshold: 1, successThreshold: 1, openTimeoutSeconds: 60);
        $cb->recordFailure();

        $policy = ResiliencePolicy::create()->withCircuitBreaker($cb);

        $this->expectException(ResilienceException::class);
        $this->expectExceptionMessage('Circuit breaker "api" is open');

        $policy->execute(fn(): string => 'should not run');
    }

    #[Test]
    public function circuitBreakerLayerAllowsWhenClosed(): void
    {
        $cb = new CircuitBreaker(name: 'api', failureThreshold: 5, successThreshold: 1, openTimeoutSeconds: 60);

        $policy = ResiliencePolicy::create()->withCircuitBreaker($cb);

        $result = $policy->execute(fn(): string => 'passed through');

        self::assertSame('passed through', $result);
    }

    #[Test]
    public function bulkheadLayerLimitsConcurrency(): void
    {
        $bulkhead = new BulkheadLimiter(resource: 'db', maxConcurrent: 1);
        $policy = ResiliencePolicy::create()->withBulkhead($bulkhead);

        $this->expectException(ResilienceException::class);
        $this->expectExceptionMessage('Bulkhead for "db" is full');

        $policy->execute(function () use ($policy): void {
            $policy->execute(fn(): string => 'inner');
        });
    }

    #[Test]
    public function bulkheadLayerAllowsWithinCapacity(): void
    {
        $bulkhead = new BulkheadLimiter(resource: 'db', maxConcurrent: 5);
        $policy = ResiliencePolicy::create()->withBulkhead($bulkhead);

        $result = $policy->execute(fn(): string => 'executed');

        self::assertSame('executed', $result);
    }

    #[Test]
    public function allLayersCanBeComposed(): void
    {
        $retry = new RetryPolicy(maxAttempts: 2, baseDelayMs: 1, maxDelayMs: 10, multiplier: 1.0, jitter: false);
        $cb = new CircuitBreaker(name: 'test', failureThreshold: 5, successThreshold: 1, openTimeoutSeconds: 60);
        $bulkhead = new BulkheadLimiter(resource: 'test', maxConcurrent: 10);

        $policy = ResiliencePolicy::create()
            ->withRetry($retry)
            ->withCircuitBreaker($cb)
            ->withTimeout(5000)
            ->withBulkhead($bulkhead);

        $result = $policy->execute(fn(): string => 'all layers active');

        self::assertSame('all layers active', $result);
    }

    #[Test]
    public function operationReturnValueIsPreservedThroughLayers(): void
    {
        $retry = new RetryPolicy(maxAttempts: 1, baseDelayMs: 1, maxDelayMs: 10, multiplier: 1.0, jitter: false);
        $policy = ResiliencePolicy::create()->withRetry($retry);

        $result = $policy->execute(fn(): array => ['key' => 'value']);

        self::assertSame(['key' => 'value'], $result);
    }
}
