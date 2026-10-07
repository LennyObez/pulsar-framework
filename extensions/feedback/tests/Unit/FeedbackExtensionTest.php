<?php

declare(strict_types=1);

namespace Pulsar\Extension\Feedback\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\ExtensionInterface;
use Pulsar\Extension\Feedback\FeedbackExtension;
use Pulsar\Extension\Feedback\FeedbackServiceProvider;
use Pulsar\Routing\RouterInterface;

final class FeedbackExtensionTest extends TestCase
{
    #[Test]
    public function implementsExtensionInterface(): void
    {
        $ext = new FeedbackExtension();

        self::assertInstanceOf(ExtensionInterface::class, $ext);
    }

    #[Test]
    public function nameReturnsPulsarFeedback(): void
    {
        $ext = new FeedbackExtension();

        self::assertSame('pulsar/feedback', $ext->name());
    }

    #[Test]
    public function providersReturnsFeedbackServiceProvider(): void
    {
        $ext = new FeedbackExtension();
        $providers = $ext->providers();

        self::assertCount(1, $providers);
        self::assertSame(FeedbackServiceProvider::class, $providers[0]);
    }

    #[Test]
    public function bootRegistersApiAndAdminRoutes(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $router = $this->createMock(RouterInterface::class);

        // The public API keeps the router sugar: 2 GET, 1 POST, no guard needed.
        $router->expects(self::exactly(2))->method('get');
        $router->expects(self::once())->method('post');

        // The 5 admin routes go through add() instead, because the sugar cannot
        // carry the auth middleware and the permission attribute they now require.
        // FeedbackRouteSecurityTest asserts what those routes actually declare.
        $router->expects(self::exactly(5))->method('add');

        $ext = new FeedbackExtension();
        $ext->boot($container, $router);
    }
}
