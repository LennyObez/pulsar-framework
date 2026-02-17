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

        // Expect 8 routes (3 API + 5 admin)
        $router->expects(self::exactly(3))->method('get');
        $router->expects(self::exactly(3))->method('post');
        $router->expects(self::once())->method('put');

        $ext = new FeedbackExtension();
        $ext->boot($container, $router);
    }
}
