<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Subscriptions;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extension\Subscriptions\SubscriptionsExtension;
use Pulsar\Extension\Subscriptions\SubscriptionsServiceProvider;
use Pulsar\Routing\RouterInterface;

final class SubscriptionsExtensionTest extends TestCase
{
    private SubscriptionsExtension $extension;

    protected function setUp(): void
    {
        $this->extension = new SubscriptionsExtension();
    }

    #[Test]
    public function nameReturnsPulsarSubscriptions(): void
    {
        self::assertSame('pulsar/subscriptions', $this->extension->name());
    }

    #[Test]
    public function providersReturnsSubscriptionsServiceProvider(): void
    {
        $providers = $this->extension->providers();

        self::assertCount(1, $providers);
        self::assertSame(SubscriptionsServiceProvider::class, $providers[0]);
    }

    #[Test]
    public function registerIsNoOp(): void
    {
        $container = $this->createStub(ContainerInterface::class);

        $this->extension->register($container);

        // register() is intentionally empty — verify the extension is still valid
        self::assertSame('pulsar/subscriptions', $this->extension->name());
    }

    #[Test]
    public function bootRegistersApiAndWebhookRoutes(): void
    {
        $container = $this->createStub(ContainerInterface::class);

        /** @var RouterInterface&MockObject $router */
        $router = $this->createMock(RouterInterface::class);

        // API routes: verify (POST), status (GET), restore (POST)
        // Webhook routes: google-play (POST), apple-sns (POST)
        // Total: post() called 4 times, get() called 1 time
        $router->expects(self::exactly(4))
            ->method('post')
            ->willReturnSelf();

        $router->expects(self::once())
            ->method('get')
            ->willReturnSelf();

        $this->extension->boot($container, $router);
    }

    #[Test]
    public function bootRegistersCorrectApiRoutePaths(): void
    {
        $container = $this->createStub(ContainerInterface::class);

        $postCalls = [];
        $getCalls = [];

        /** @var RouterInterface&MockObject $router */
        $router = $this->createMock(RouterInterface::class);
        $router->expects(self::exactly(4))
            ->method('post')
            ->willReturnCallback(function (string $path, mixed $handler, ?string $name = null) use ($router, &$postCalls): RouterInterface {
                $postCalls[] = ['path' => $path, 'name' => $name];

                return $router;
            });

        $router->expects(self::once())
            ->method('get')
            ->willReturnCallback(function (string $path, mixed $handler, ?string $name = null) use ($router, &$getCalls): RouterInterface {
                $getCalls[] = ['path' => $path, 'name' => $name];

                return $router;
            });

        $this->extension->boot($container, $router);

        // Verify API route paths
        self::assertSame('/api/v1/subscriptions/verify', $postCalls[0]['path']);
        self::assertSame('subscriptions.api.verify', $postCalls[0]['name']);

        self::assertSame('/api/v1/subscriptions/status', $getCalls[0]['path']);
        self::assertSame('subscriptions.api.status', $getCalls[0]['name']);

        self::assertSame('/api/v1/subscriptions/restore', $postCalls[1]['path']);
        self::assertSame('subscriptions.api.restore', $postCalls[1]['name']);

        // Verify webhook route paths
        self::assertSame('/api/v1/webhooks/google-play', $postCalls[2]['path']);
        self::assertSame('subscriptions.webhooks.google_play', $postCalls[2]['name']);

        self::assertSame('/api/v1/webhooks/apple-sns', $postCalls[3]['path']);
        self::assertSame('subscriptions.webhooks.apple_sns', $postCalls[3]['name']);
    }
}
