<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\ContainerInterface;
use Pulsar\Core\Kernel;
use Pulsar\Routing\Router;

#[CoversClass(Kernel::class)]
final class KernelTest extends TestCase
{
    #[Test]
    public function kernelIsNotBootedByDefault(): void
    {
        $kernel = new Kernel();

        self::assertFalse($kernel->isBooted());
    }

    #[Test]
    public function kernelCanBoot(): void
    {
        $kernel = new Kernel();
        $kernel->boot();

        self::assertTrue($kernel->isBooted());
    }

    #[Test]
    public function kernelBootIsIdempotent(): void
    {
        $kernel = new Kernel();
        $kernel->boot();
        $kernel->boot();

        self::assertTrue($kernel->isBooted());
    }

    #[Test]
    public function kernelCanShutdown(): void
    {
        $kernel = new Kernel();
        $kernel->boot();
        $kernel->shutdown();

        self::assertFalse($kernel->isBooted());
    }

    #[Test]
    public function kernelProvidesContainer(): void
    {
        $kernel = new Kernel();

        self::assertInstanceOf(ContainerInterface::class, $kernel->container());
    }

    #[Test]
    public function kernelProvidesRouter(): void
    {
        $kernel = new Kernel();

        self::assertInstanceOf(Router::class, $kernel->router());
    }

    #[Test]
    public function kernelRegistersItselfInContainer(): void
    {
        $kernel = new Kernel();

        self::assertSame($kernel, $kernel->container()->get(Kernel::class));
    }

    #[Test]
    public function kernelRegistersRouterInContainer(): void
    {
        $kernel = new Kernel();

        self::assertSame($kernel->router(), $kernel->container()->get(Router::class));
    }

    #[Test]
    public function kernelAcceptsCustomContainer(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(false);
        $container->expects($this->atLeast(3))->method('instance');

        $kernel = new Kernel($container);

        self::assertSame($container, $kernel->container());
    }

    #[Test]
    public function kernelAcceptsCustomRouter(): void
    {
        $router = new Router();

        $kernel = new Kernel(router: $router);

        self::assertSame($router, $kernel->router());
    }
}
