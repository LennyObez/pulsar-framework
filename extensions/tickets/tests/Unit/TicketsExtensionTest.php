<?php

declare(strict_types=1);

namespace Pulsar\Extension\Tickets\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extension\Tickets\Config\TicketsConfig;
use Pulsar\Extension\Tickets\TicketsExtension;
use Pulsar\Extension\Tickets\TicketsServiceProvider;
use Pulsar\Routing\RouterInterface;

final class TicketsExtensionTest extends TestCase
{
    #[Test]
    public function nameReturnsPulsarTickets(): void
    {
        $extension = new TicketsExtension();

        self::assertSame('pulsar/tickets', $extension->name());
    }

    #[Test]
    public function providersReturnsServiceProvider(): void
    {
        $extension = new TicketsExtension();
        $providers = $extension->providers();

        self::assertCount(1, $providers);
        self::assertSame(TicketsServiceProvider::class, $providers[0]);
    }

    #[Test]
    public function preBootRegistersDefaultConfig(): void
    {
        $extension = new TicketsExtension();

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturnMap([
            [TicketsConfig::class, false],
            ['Pulsar\Config\ConfigManagerInterface', false],
        ]);

        $container->expects(self::once())
            ->method('instance')
            ->with(
                TicketsConfig::class,
                self::isInstanceOf(TicketsConfig::class),
            );

        $extension->preBoot($container);
    }

    #[Test]
    public function preBootSkipsWhenConfigAlreadyRegistered(): void
    {
        $extension = new TicketsExtension();

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturnMap([
            [TicketsConfig::class, true],
        ]);

        $container->expects(self::never())->method('instance');

        $extension->preBoot($container);
    }

    #[Test]
    public function bootRegistersRoutes(): void
    {
        $extension = new TicketsExtension();

        $container = $this->createStub(ContainerInterface::class);
        $router = $this->createMock(RouterInterface::class);

        // Verify public routes + admin routes are registered
        $router->expects(self::atLeast(3))->method('post');
        $router->expects(self::atLeast(3))->method('get');

        $extension->boot($container, $router);
    }
}
