<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extension\Forum\ForumExtension;
use Pulsar\Extension\Forum\ForumServiceProvider;
use Pulsar\Routing\RouterInterface;

final class ForumExtensionTest extends TestCase
{
    #[Test]
    public function nameReturnsPulsarForum(): void
    {
        $extension = new ForumExtension();

        self::assertSame('pulsar/forum', $extension->name());
    }

    #[Test]
    public function providersReturnsForumServiceProvider(): void
    {
        $extension = new ForumExtension();
        $providers = $extension->providers();

        self::assertContains(ForumServiceProvider::class, $providers);
    }

    #[Test]
    public function registerDoesNotThrow(): void
    {
        $extension = new ForumExtension();
        $container = $this->createStub(ContainerInterface::class);

        $extension->register($container);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function bootRegistersRoutes(): void
    {
        $extension = new ForumExtension();
        $container = $this->createStub(ContainerInterface::class);

        $router = $this->createMock(RouterInterface::class);
        $router->expects($this->atLeastOnce())->method('get');
        $router->expects($this->atLeastOnce())->method('post');

        $extension->boot($container, $router);
    }
}
