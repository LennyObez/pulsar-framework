<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Subscriptions;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\ContainerInterface;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Extension\Subscriptions\Http\Controller\SubscriptionController;
use Pulsar\Extension\Subscriptions\Http\Controller\WebhookController;
use Pulsar\Extension\Subscriptions\Http\Middleware\SubscriptionTokenGuard;
use Pulsar\Extension\Subscriptions\Internal\SubscriptionService;
use Pulsar\Extension\Subscriptions\SubscriptionRepositoryInterface;
use Pulsar\Extension\Subscriptions\SubscriptionsServiceProvider;
use Pulsar\Extension\Subscriptions\SubscriptionVerifierInterface;
use Pulsar\Extension\Subscriptions\WebhookEventRepositoryInterface;

final class SubscriptionsServiceProviderTest extends TestCase
{
    private SubscriptionsServiceProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new SubscriptionsServiceProvider();
    }

    #[Test]
    public function providesReturnsSevenClassStrings(): void
    {
        $provides = $this->provider->provides();

        self::assertCount(7, $provides);
    }

    #[Test]
    public function providesContainsExpectedClasses(): void
    {
        $provides = $this->provider->provides();

        self::assertContains(SubscriptionRepositoryInterface::class, $provides);
        self::assertContains(WebhookEventRepositoryInterface::class, $provides);
        self::assertContains(SubscriptionVerifierInterface::class, $provides);
        self::assertContains(SubscriptionService::class, $provides);
        self::assertContains(SubscriptionController::class, $provides);
        self::assertContains(WebhookController::class, $provides);
        self::assertContains(SubscriptionTokenGuard::class, $provides);
    }

    #[Test]
    public function registerReturnsEarlyWhenNoConnectionInterface(): void
    {
        /** @var ContainerInterface&MockObject $container */
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')
            ->willReturnCallback(static fn(string $id): bool => match ($id) {
                ConnectionInterface::class => false,
                default => false,
            });

        $container->expects(self::never())
            ->method('instance');

        $this->provider->register($container);
    }

    #[Test]
    public function registerBindsAllServicesWhenConnectionAvailable(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);

        $boundIds = [];

        /** @var ContainerInterface&MockObject $container */
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')
            ->willReturnCallback(static fn(string $id): bool => match ($id) {
                ConnectionInterface::class => true,
                default => false,
            });

        $container->method('get')
            ->willReturnCallback(static fn(string $id): mixed => match ($id) {
                ConnectionInterface::class => $connection,
                default => null,
            });

        // SubscriptionRepositoryInterface, WebhookEventRepositoryInterface,
        // SubscriptionVerifierInterface, SubscriptionService,
        // SubscriptionController, WebhookController, SubscriptionTokenGuard
        $container->expects(self::exactly(7))
            ->method('instance')
            ->willReturnCallback(function (string $id, object $instance) use (&$boundIds): void {
                $boundIds[] = $id;
            });

        $this->provider->register($container);

        self::assertContains(SubscriptionRepositoryInterface::class, $boundIds);
        self::assertContains(WebhookEventRepositoryInterface::class, $boundIds);
        self::assertContains(SubscriptionVerifierInterface::class, $boundIds);
        self::assertContains(SubscriptionService::class, $boundIds);
        self::assertContains(SubscriptionController::class, $boundIds);
        self::assertContains(WebhookController::class, $boundIds);
        self::assertContains(SubscriptionTokenGuard::class, $boundIds);
    }

    #[Test]
    public function registerUsesNullLoggerWhenLoggerNotAvailable(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);

        /** @var ContainerInterface&MockObject $container */
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')
            ->willReturnCallback(static fn(string $id): bool => match ($id) {
                ConnectionInterface::class => true,
                default => false,
            });

        $container->method('get')
            ->willReturnCallback(static fn(string $id): mixed => match ($id) {
                ConnectionInterface::class => $connection,
                default => null,
            });

        // Should not throw — NullLogger is used as fallback
        $container->expects(self::exactly(7))
            ->method('instance');

        $this->provider->register($container);
    }

    #[Test]
    public function registerSkipsTokenGuardWhenAlreadyBound(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);

        $boundIds = [];

        /** @var ContainerInterface&MockObject $container */
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')
            ->willReturnCallback(static fn(string $id): bool => match ($id) {
                ConnectionInterface::class, SubscriptionTokenGuard::class => true,
                default => false,
            });

        $container->method('get')
            ->willReturnCallback(static fn(string $id): mixed => match ($id) {
                ConnectionInterface::class => $connection,
                default => null,
            });

        // 6 bindings instead of 7 — SubscriptionTokenGuard is already bound
        $container->expects(self::exactly(6))
            ->method('instance')
            ->willReturnCallback(function (string $id, object $instance) use (&$boundIds): void {
                $boundIds[] = $id;
            });

        $this->provider->register($container);

        self::assertNotContains(SubscriptionTokenGuard::class, $boundIds);
    }
}
