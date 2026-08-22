<?php

declare(strict_types=1);

namespace Pulsar\Extension\Eidas\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extension\Eidas\EidasExtension;
use Pulsar\Extension\Eidas\EidasServiceProvider;
use Pulsar\Routing\RouterInterface;

#[CoversClass(EidasExtension::class)]
final class EidasExtensionTest extends TestCase
{
    private EidasExtension $extension;

    protected function setUp(): void
    {
        $this->extension = new EidasExtension();
    }

    #[Test]
    public function nameReturnsPulsarEidas(): void
    {
        self::assertSame('pulsar/eidas', $this->extension->name());
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
    public function providersReturnsEidasServiceProvider(): void
    {
        $providers = $this->extension->providers();

        self::assertCount(1, $providers);
        self::assertSame(EidasServiceProvider::class, $providers[0]);
    }
}
