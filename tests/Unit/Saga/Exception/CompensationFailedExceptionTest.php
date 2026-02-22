<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Saga\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Saga\Exception\CompensationFailedException;
use Pulsar\Saga\Exception\SagaException;
use RuntimeException;

#[CoversClass(CompensationFailedException::class)]
final class CompensationFailedExceptionTest extends TestCase
{
    #[Test]
    public function test_forStep_creates_exception(): void
    {
        $cause = new RuntimeException('Refund service unavailable');
        $exception = CompensationFailedException::forStep('saga-1', 'charge_payment', $cause);

        self::assertInstanceOf(CompensationFailedException::class, $exception);
        self::assertStringContainsString('saga-1', $exception->getMessage());
        self::assertStringContainsString('charge_payment', $exception->getMessage());
        self::assertStringContainsString('Refund service unavailable', $exception->getMessage());
    }

    #[Test]
    public function test_forStep_preserves_cause(): void
    {
        $cause = new RuntimeException('Connection timeout');
        $exception = CompensationFailedException::forStep('saga-1', 'step', $cause);

        self::assertSame($cause, $exception->getPrevious());
    }

    #[Test]
    public function test_extends_SagaException(): void
    {
        $cause = new RuntimeException('Error');
        $exception = CompensationFailedException::forStep('saga-1', 'step', $cause);

        self::assertInstanceOf(SagaException::class, $exception);
    }
}
