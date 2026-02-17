<?php

declare(strict_types=1);

namespace Pulsar\Extension\Dsa\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extension\Dsa\DsaExtension;
use Pulsar\Extension\Dsa\DsaServiceProvider;
use Pulsar\Routing\RouterInterface;

#[CoversClass(DsaExtension::class)]
final class DsaExtensionTest extends TestCase
{
    private DsaExtension $extension;

    protected function setUp(): void
    {
        $this->extension = new DsaExtension();
    }

    #[Test]
    public function nameReturnsPulsarDsa(): void
    {
        self::assertSame('pulsar/dsa', $this->extension->name());
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
    public function providersReturnsDsaServiceProvider(): void
    {
        $providers = $this->extension->providers();

        self::assertCount(1, $providers);
        self::assertSame(DsaServiceProvider::class, $providers[0]);
    }
}
