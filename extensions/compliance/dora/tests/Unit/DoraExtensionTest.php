<?php

declare(strict_types=1);

namespace Pulsar\Extension\Dora\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extension\Dora\DoraExtension;
use Pulsar\Extension\Dora\DoraServiceProvider;
use Pulsar\Routing\RouterInterface;

#[CoversClass(DoraExtension::class)]
final class DoraExtensionTest extends TestCase
{
    private DoraExtension $extension;

    protected function setUp(): void
    {
        $this->extension = new DoraExtension();
    }

    #[Test]
    public function nameReturnsPulsarDora(): void
    {
        self::assertSame('pulsar/dora', $this->extension->name());
    }

    #[Test]
    public function registerDoesNotThrow(): void
    {
        $container = $this->createStub(ContainerInterface::class);

        $this->extension->register($container);

        self::assertTrue(true, 'register() completed without exception');
    }

    #[Test]
    public function bootDoesNotRegisterRoutes(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $router = $this->createMock(RouterInterface::class);
        $router->expects(self::never())->method(self::anything());

        $this->extension->boot($container, $router);
    }

    #[Test]
    public function providersReturnsDoraServiceProvider(): void
    {
        $providers = $this->extension->providers();

        self::assertCount(1, $providers);
        self::assertSame(DoraServiceProvider::class, $providers[0]);
    }
}
