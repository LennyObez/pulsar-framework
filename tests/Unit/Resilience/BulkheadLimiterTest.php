<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Resilience;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Resilience\BulkheadLimiter;
use Pulsar\Resilience\Exception\ResilienceException;
use RuntimeException;

#[CoversClass(BulkheadLimiter::class)]
final class BulkheadLimiterTest extends TestCase
{
    #[Test]
    public function executeRunsOperationWhenSlotAvailable(): void
    {
        $limiter = new BulkheadLimiter(resource: 'db', maxConcurrent: 5);

        $result = $limiter->execute(fn(): string => 'completed');

        self::assertSame('completed', $result);
    }

    #[Test]
    public function activeCountIncrementsAndDecrementsAroundExecution(): void
    {
        $limiter = new BulkheadLimiter(resource: 'db', maxConcurrent: 5);

        self::assertSame(0, $limiter->activeCount());

        $capturedActive = null;
        $limiter->execute(function () use ($limiter, &$capturedActive): string {
            $capturedActive = $limiter->activeCount();
            return 'ok';
        });

        self::assertSame(1, $capturedActive);
        self::assertSame(0, $limiter->activeCount());
    }

    #[Test]
    public function throwsBulkheadFullWhenMaxConcurrentReached(): void
    {
        $limiter = new BulkheadLimiter(resource: 'api-gateway', maxConcurrent: 1);

        $this->expectException(ResilienceException::class);
        $this->expectExceptionMessage('Bulkhead for "api-gateway" is full');

        $limiter->execute(function () use ($limiter): void {
            // While one execution is active, a second should be rejected
            $limiter->execute(fn(): string => 'should not run');
        });
    }

    #[Test]
    public function activeCountDecrementsOnException(): void
    {
        $limiter = new BulkheadLimiter(resource: 'db', maxConcurrent: 5);

        try {
            $limiter->execute(function (): never {
                throw new RuntimeException('operation failed');
            });
        } catch (RuntimeException) {
            // Expected
        }

        self::assertSame(0, $limiter->activeCount());
    }

    #[Test]
    public function operationExceptionIsRethrown(): void
    {
        $limiter = new BulkheadLimiter(resource: 'db', maxConcurrent: 5);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('db error');

        $limiter->execute(function (): never {
            throw new RuntimeException('db error');
        });
    }

    #[Test]
    public function maxConcurrentReturnsConfiguredValue(): void
    {
        $limiter = new BulkheadLimiter(resource: 'db', maxConcurrent: 10);

        self::assertSame(10, $limiter->maxConcurrent());
    }

    #[Test]
    public function availableSlotsReflectsActiveCount(): void
    {
        $limiter = new BulkheadLimiter(resource: 'db', maxConcurrent: 3);

        self::assertSame(3, $limiter->availableSlots());

        $limiter->execute(function () use ($limiter): void {
            self::assertSame(2, $limiter->availableSlots());
        });

        self::assertSame(3, $limiter->availableSlots());
    }

    #[Test]
    public function resourceReturnsName(): void
    {
        $limiter = new BulkheadLimiter(resource: 'payment-processor', maxConcurrent: 5);

        self::assertSame('payment-processor', $limiter->resource());
    }

    #[Test]
    public function operationReturnValueIsPreserved(): void
    {
        $limiter = new BulkheadLimiter(resource: 'cache', maxConcurrent: 10);

        $result = $limiter->execute(fn(): array => ['status' => 'ok', 'code' => 200]);

        self::assertSame(['status' => 'ok', 'code' => 200], $result);
    }

    #[Test]
    public function returnsNullWhenOperationReturnsNull(): void
    {
        $limiter = new BulkheadLimiter(resource: 'cache', maxConcurrent: 10);

        $result = $limiter->execute(fn(): null => null);

        self::assertNull($result);
    }
}
