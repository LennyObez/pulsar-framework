<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psd2\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extension\Psd2\Psd2Extension;
use Pulsar\Extension\Psd2\Psd2ServiceProvider;
use Pulsar\Routing\RouterInterface;

#[CoversClass(Psd2Extension::class)]
final class Psd2ExtensionTest extends TestCase
{
    private Psd2Extension $extension;

    protected function setUp(): void
    {
        $this->extension = new Psd2Extension();
    }

    #[Test]
    public function nameReturnsPulsarPsd2(): void
    {
        self::assertSame('pulsar/psd2', $this->extension->name());
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
    public function providersReturnsPsd2ServiceProvider(): void
    {
        $providers = $this->extension->providers();

        self::assertCount(1, $providers);
        self::assertSame(Psd2ServiceProvider::class, $providers[0]);
    }
}
