<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Saga;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Saga\Exception\ForbiddenInjectionException;
use Pulsar\Saga\Internal\SagaContainerGuard;
use Pulsar\Saga\Port\CommandBusPort;
use Pulsar\Saga\Port\IntegrationEventBusPort;
use Pulsar\Saga\Port\OutboxPort;

#[CoversClass(SagaContainerGuard::class)]
final class SagaContainerGuardTest extends TestCase
{
    #[Test]
    public function test_throws_when_saga_step_handler_resolves_IntegrationEventBusPort(): void
    {
        $guard = new SagaContainerGuard(enabled: true);

        $this->expectException(ForbiddenInjectionException::class);
        $this->expectExceptionMessageIsOrContains('IntegrationEventBusPort');

        $guard->assertAllowed(IntegrationEventBusPort::class, 'App\\Saga\\Step\\ChargePaymentHandler');
    }

    #[Test]
    public function test_throws_for_handler_namespace_pattern(): void
    {
        $guard = new SagaContainerGuard(enabled: true);

        $this->expectException(ForbiddenInjectionException::class);

        $guard->assertAllowed(IntegrationEventBusPort::class, 'App\\Saga\\Handler\\ProcessOrderHandler');
    }

    #[Test]
    public function test_throws_for_action_namespace_pattern(): void
    {
        $guard = new SagaContainerGuard(enabled: true);

        $this->expectException(ForbiddenInjectionException::class);

        $guard->assertAllowed(IntegrationEventBusPort::class, 'App\\Saga\\Action\\RefundAction');
    }

    #[Test]
    public function test_allows_OutboxPort_for_saga_step_handler(): void
    {
        $this->expectNotToPerformAssertions();

        $guard = new SagaContainerGuard(enabled: true);

        $guard->assertAllowed(OutboxPort::class, 'App\\Saga\\Step\\ChargePaymentHandler');
    }

    #[Test]
    public function test_allows_CommandBusPort_for_saga_step_handler(): void
    {
        $this->expectNotToPerformAssertions();

        $guard = new SagaContainerGuard(enabled: true);

        $guard->assertAllowed(CommandBusPort::class, 'App\\Saga\\Step\\ChargePaymentHandler');
    }

    #[Test]
    public function test_allows_IntegrationEventBusPort_for_non_saga_handler(): void
    {
        $this->expectNotToPerformAssertions();

        $guard = new SagaContainerGuard(enabled: true);

        $guard->assertAllowed(IntegrationEventBusPort::class, 'App\\Controller\\WebhookController');
    }

    #[Test]
    public function test_allows_everything_when_disabled(): void
    {
        $this->expectNotToPerformAssertions();

        $guard = new SagaContainerGuard(enabled: false);

        $guard->assertAllowed(IntegrationEventBusPort::class, 'App\\Saga\\Step\\ChargePaymentHandler');
    }

    #[Test]
    #[DataProvider('sagaStepNamespaceProvider')]
    public function test_isSagaStepHandler_detection(string $className, bool $shouldBlock): void
    {
        $guard = new SagaContainerGuard(enabled: true);

        if ($shouldBlock) {
            $this->expectException(ForbiddenInjectionException::class);
        } else {
            $this->expectNotToPerformAssertions();
        }

        $guard->assertAllowed(IntegrationEventBusPort::class, $className);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function sagaStepNamespaceProvider(): iterable
    {
        yield 'Saga\\Step\\ pattern' => ['Acme\\Saga\\Step\\DoSomething', true];
        yield 'Saga\\Handler\\ pattern' => ['Acme\\Saga\\Handler\\HandlePayment', true];
        yield 'Saga\\Action\\ pattern' => ['Acme\\Saga\\Action\\RefundPayment', true];
        yield 'Controller class' => ['App\\Controller\\IndexController', false];
        yield 'Service class' => ['App\\Service\\PaymentService', false];
        yield 'Listener class' => ['App\\Listener\\OrderListener', false];
        yield 'Saga root namespace' => ['App\\Saga\\SagaRunner', false];
    }
}
