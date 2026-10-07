<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Saga\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Saga\Exception\ForbiddenInjectionException;
use Pulsar\Saga\Exception\SagaException;

#[CoversClass(ForbiddenInjectionException::class)]
final class ForbiddenInjectionExceptionTest extends TestCase
{
    #[Test]
    public function test_integrationEventBusInSagaStep(): void
    {
        $exception = ForbiddenInjectionException::integrationEventBusInSagaStep(
            'App\\Saga\\Step\\ChargePaymentHandler',
        );

        self::assertInstanceOf(ForbiddenInjectionException::class, $exception);
        self::assertStringContainsString('ChargePaymentHandler', $exception->getMessage());
        self::assertStringContainsString('IntegrationEventBusPort', $exception->getMessage());
        self::assertStringContainsString('OutboxPort', $exception->getMessage());
    }

    #[Test]
    public function test_extends_SagaException(): void
    {
        $exception = ForbiddenInjectionException::integrationEventBusInSagaStep('Handler');

        self::assertInstanceOf(SagaException::class, $exception);
    }
}
