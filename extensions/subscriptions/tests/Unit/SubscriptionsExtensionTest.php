<?php

declare(strict_types=1);

namespace Pulsar\Extension\Subscriptions\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extension\Subscriptions\SubscriptionsExtension;
use Pulsar\Extension\Subscriptions\SubscriptionsServiceProvider;
use Pulsar\Routing\RouterInterface;

#[CoversClass(SubscriptionsExtension::class)]
final class SubscriptionsExtensionTest extends TestCase
{
    #[Test]
    public function nameReturnsPulsarSubscriptions(): void
    {
        $extension = new SubscriptionsExtension();

        self::assertSame('pulsar/subscriptions', $extension->name());
    }

    #[Test]
    public function providersReturnsServiceProviderClass(): void
    {
        $extension = new SubscriptionsExtension();
        $providers = $extension->providers();

        self::assertCount(1, $providers);
        self::assertSame(SubscriptionsServiceProvider::class, $providers[0]);
    }

    #[Test]
    public function registerDoesNotThrow(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $extension = new SubscriptionsExtension();

        $extension->register($container);

        // register() is intentionally empty; verify no exception was thrown
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function bootRegistersApiAndWebhookRoutes(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $router = $this->createMock(RouterInterface::class);

        // 2 API POST (verify, restore) + 2 webhook POST (google-play, apple-sns) = 4 post calls
        $router->expects(self::exactly(4))
            ->method('post')
            ->willReturnSelf();

        // 1 API GET (status)
        $router->expects(self::once())
            ->method('get')
            ->willReturnSelf();

        $extension = new SubscriptionsExtension();
        $extension->boot($container, $router);
    }

    #[Test]
    public function bootRegistersVerifyStatusRestoreAndWebhookPaths(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $registeredPaths = [];

        $router = $this->createStub(RouterInterface::class);
        $router->method('post')->willReturnCallback(
            function (string $path) use ($router, &$registeredPaths) {
                $registeredPaths[] = $path;
                return $router;
            },
        );
        $router->method('get')->willReturnCallback(
            function (string $path) use ($router, &$registeredPaths) {
                $registeredPaths[] = $path;
                return $router;
            },
        );

        $extension = new SubscriptionsExtension();
        $extension->boot($container, $router);

        self::assertContains('/api/v1/subscriptions/verify', $registeredPaths);
        self::assertContains('/api/v1/subscriptions/status', $registeredPaths);
        self::assertContains('/api/v1/subscriptions/restore', $registeredPaths);
        self::assertContains('/api/v1/webhooks/google-play', $registeredPaths);
        self::assertContains('/api/v1/webhooks/apple-sns', $registeredPaths);
    }
}
