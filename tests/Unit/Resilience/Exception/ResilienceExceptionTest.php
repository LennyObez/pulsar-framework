<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Resilience\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Resilience\Exception\ResilienceException;
use RuntimeException;

#[CoversClass(ResilienceException::class)]
final class ResilienceExceptionTest extends TestCase
{
    #[Test]
    public function circuitOpen(): void
    {
        $e = ResilienceException::circuitOpen('payment-gateway');

        self::assertStringContainsString('payment-gateway', $e->getMessage());
        self::assertStringContainsString('open', $e->getMessage());
    }

    #[Test]
    public function retryExhausted(): void
    {
        $previous = new RuntimeException('connection timeout');
        $e = ResilienceException::retryExhausted('sendEmail', 3, $previous);

        self::assertStringContainsString('sendEmail', $e->getMessage());
        self::assertStringContainsString('3', $e->getMessage());
        self::assertSame($previous, $e->getPrevious());
    }

    #[Test]
    public function retryExhaustedWithoutPrevious(): void
    {
        $e = ResilienceException::retryExhausted('fetchData', 5);

        self::assertStringContainsString('fetchData', $e->getMessage());
        self::assertNull($e->getPrevious());
    }

    #[Test]
    public function healthCheckFailed(): void
    {
        $e = ResilienceException::healthCheckFailed('database', 'connection refused');

        self::assertStringContainsString('database', $e->getMessage());
        self::assertStringContainsString('connection refused', $e->getMessage());
    }

    #[Test]
    public function repairFailed(): void
    {
        $e = ResilienceException::repairFailed('cache-rebuild', 'out of memory');

        self::assertStringContainsString('cache-rebuild', $e->getMessage());
        self::assertStringContainsString('out of memory', $e->getMessage());
    }
}
