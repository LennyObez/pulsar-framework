<?php

declare(strict_types=1);

namespace Pulsar\Extension\Subscriptions\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
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

#[CoversClass(SubscriptionsServiceProvider::class)]
final class SubscriptionsServiceProviderTest extends TestCase
{
    #[Test]
    public function providesReturnsAllExpectedServiceIds(): void
    {
        $provider = new SubscriptionsServiceProvider();
        $provides = $provider->provides();

        self::assertContains(SubscriptionRepositoryInterface::class, $provides);
        self::assertContains(WebhookEventRepositoryInterface::class, $provides);
        self::assertContains(SubscriptionVerifierInterface::class, $provides);
        self::assertContains(SubscriptionService::class, $provides);
        self::assertContains(SubscriptionController::class, $provides);
        self::assertContains(WebhookController::class, $provides);
        self::assertContains(SubscriptionTokenGuard::class, $provides);
    }

    #[Test]
    public function registerSkipsWhenNoConnectionAvailable(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturn(false);

        // instance() should never be called if no connection
        $mock = $this->createMock(ContainerInterface::class);
        $mock->method('has')->willReturn(false);
        $mock->expects(self::never())->method('instance');

        $provider = new SubscriptionsServiceProvider();
        $provider->register($mock);
    }

    #[Test]
    public function registerBindsAllServicesWhenConnectionAvailable(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $logger = $this->createStub(LoggerInterface::class);

        $registeredIds = [];
        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturnCallback(
            function (string $id) {
                return match ($id) {
                    ConnectionInterface::class => true,
                    LoggerInterface::class => true,
                    SubscriptionTokenGuard::class => false,
                    default => false,
                };
            },
        );
        $container->method('get')->willReturnCallback(
            function (string $id) use ($connection, $logger) {
                return match ($id) {
                    ConnectionInterface::class => $connection,
                    LoggerInterface::class => $logger,
                    default => null,
                };
            },
        );
        $container->method('instance')->willReturnCallback(
            function (string $id) use (&$registeredIds): void {
                $registeredIds[] = $id;
            },
        );

        $provider = new SubscriptionsServiceProvider();
        $provider->register($container);

        self::assertContains(SubscriptionRepositoryInterface::class, $registeredIds);
        self::assertContains(WebhookEventRepositoryInterface::class, $registeredIds);
        self::assertContains(SubscriptionVerifierInterface::class, $registeredIds);
        self::assertContains(SubscriptionService::class, $registeredIds);
        self::assertContains(SubscriptionController::class, $registeredIds);
        self::assertContains(WebhookController::class, $registeredIds);
        self::assertContains(SubscriptionTokenGuard::class, $registeredIds);
    }

    #[Test]
    public function registerUsesNullLoggerWhenLoggerNotInContainer(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);

        $registeredIds = [];
        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturnCallback(
            function (string $id) {
                return match ($id) {
                    ConnectionInterface::class => true,
                    default => false,
                };
            },
        );
        $container->method('get')->willReturnCallback(
            function (string $id) use ($connection) {
                return match ($id) {
                    ConnectionInterface::class => $connection,
                    default => null,
                };
            },
        );
        $container->method('instance')->willReturnCallback(
            function (string $id) use (&$registeredIds): void {
                $registeredIds[] = $id;
            },
        );

        $provider = new SubscriptionsServiceProvider();
        $provider->register($container);

        // Should still register all services even without LoggerInterface
        self::assertContains(SubscriptionService::class, $registeredIds);
    }

    #[Test]
    public function registerSkipsTokenGuardWhenAlreadyBound(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);

        $registeredIds = [];
        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturnCallback(
            function (string $id) {
                return match ($id) {
                    ConnectionInterface::class => true,
                    SubscriptionTokenGuard::class => true,  // Already bound
                    default => false,
                };
            },
        );
        $container->method('get')->willReturnCallback(
            function (string $id) use ($connection) {
                return match ($id) {
                    ConnectionInterface::class => $connection,
                    default => null,
                };
            },
        );
        $container->method('instance')->willReturnCallback(
            function (string $id) use (&$registeredIds): void {
                $registeredIds[] = $id;
            },
        );

        $provider = new SubscriptionsServiceProvider();
        $provider->register($container);

        // TokenGuard should NOT be re-registered since has() returned true
        $guardCount = array_count_values($registeredIds)[SubscriptionTokenGuard::class] ?? 0;
        self::assertSame(0, $guardCount);
    }

    #[Test]
    public function registerUsesGoogleConfigFromContainer(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);

        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturnCallback(
            function (string $id) {
                return match ($id) {
                    ConnectionInterface::class,
                    'subscriptions.google.config' => true,
                    default => false,
                };
            },
        );
        $container->method('get')->willReturnCallback(
            function (string $id) use ($connection) {
                return match ($id) {
                    ConnectionInterface::class => $connection,
                    'subscriptions.google.config' => [
                        'package_name' => 'com.example.app',
                        'service_account_json' => '/path/to/sa.json',
                    ],
                    default => null,
                };
            },
        );

        $provider = new SubscriptionsServiceProvider();
        // Should not throw when custom config is provided
        $provider->register($container);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function registerUsesAppleConfigFromContainer(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);

        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturnCallback(
            function (string $id) {
                return match ($id) {
                    ConnectionInterface::class,
                    'subscriptions.apple.config' => true,
                    default => false,
                };
            },
        );
        $container->method('get')->willReturnCallback(
            function (string $id) use ($connection) {
                return match ($id) {
                    ConnectionInterface::class => $connection,
                    'subscriptions.apple.config' => [
                        'bundle_id' => 'com.example.ios',
                        'issuer_id' => 'issuer-abc',
                        'key_id' => 'key-def',
                        'private_key_path' => '/path/to/key.p8',
                    ],
                    default => null,
                };
            },
        );

        $provider = new SubscriptionsServiceProvider();
        $provider->register($container);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function registerUsesEncryptionKeyFromContainer(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);

        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturnCallback(
            function (string $id) {
                return match ($id) {
                    ConnectionInterface::class,
                    'subscriptions.webhook.encryption_key' => true,
                    default => false,
                };
            },
        );
        $container->method('get')->willReturnCallback(
            function (string $id) use ($connection) {
                return match ($id) {
                    ConnectionInterface::class => $connection,
                    'subscriptions.webhook.encryption_key' => sodium_crypto_secretbox_keygen(),
                    default => null,
                };
            },
        );

        $provider = new SubscriptionsServiceProvider();
        $provider->register($container);

        $this->addToAssertionCount(1);
    }
}
