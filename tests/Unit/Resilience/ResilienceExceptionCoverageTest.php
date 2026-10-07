<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Resilience;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Resilience\Exception\ResilienceException;
use RuntimeException;

#[CoversClass(ResilienceException::class)]
final class ResilienceExceptionCoverageTest extends TestCase
{
    #[Test]
    public function timeoutExceptionContainsElapsedAndLimit(): void
    {
        $e = ResilienceException::timeout('slow-query', 5000, 7500);

        self::assertStringContainsString('slow-query', $e->getMessage());
        self::assertStringContainsString('7500', $e->getMessage());
        self::assertStringContainsString('5000', $e->getMessage());
        self::assertStringContainsString('timed out', $e->getMessage());
    }

    #[Test]
    public function bulkheadFullExceptionContainsResourceAndLimit(): void
    {
        $e = ResilienceException::bulkheadFull('database-pool', 25);

        self::assertStringContainsString('database-pool', $e->getMessage());
        self::assertStringContainsString('25', $e->getMessage());
        self::assertStringContainsString('full', $e->getMessage());
    }

    #[Test]
    public function circuitOpenExceptionExtendsRuntimeException(): void
    {
        $e = ResilienceException::circuitOpen('api');

        self::assertInstanceOf(RuntimeException::class, $e);
    }

    #[Test]
    public function retryExhaustedExceptionContainsAttemptCount(): void
    {
        $previous = new RuntimeException('db timeout');
        $e = ResilienceException::retryExhausted('db-query', 5, $previous);

        self::assertStringContainsString('5', $e->getMessage());
        self::assertStringContainsString('db-query', $e->getMessage());
        self::assertSame($previous, $e->getPrevious());
    }

    #[Test]
    public function healthCheckFailedExceptionFormatsCorrectly(): void
    {
        $e = ResilienceException::healthCheckFailed('redis', 'ECONNREFUSED');

        self::assertStringContainsString('redis', $e->getMessage());
        self::assertStringContainsString('ECONNREFUSED', $e->getMessage());
    }

    #[Test]
    public function repairFailedExceptionFormatsCorrectly(): void
    {
        $e = ResilienceException::repairFailed('index-rebuild', 'lock timeout');

        self::assertStringContainsString('index-rebuild', $e->getMessage());
        self::assertStringContainsString('lock timeout', $e->getMessage());
    }
}
