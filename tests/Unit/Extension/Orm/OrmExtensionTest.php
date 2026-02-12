<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Orm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extension\Orm\OrmExtension;
use Pulsar\Extension\Orm\OrmServiceProvider;
use Pulsar\Routing\RouterInterface;

#[CoversClass(OrmExtension::class)]
final class OrmExtensionTest extends TestCase
{
    private OrmExtension $extension;

    protected function setUp(): void
    {
        $this->extension = new OrmExtension();
    }

    #[Test]
    public function nameReturnsPulsarOrm(): void
    {
        self::assertSame('pulsar/orm', $this->extension->name());
    }

    #[Test]
    public function registerDoesNotThrow(): void
    {
        $container = $this->createStub(ContainerInterface::class);

        $this->extension->register($container);

        // register() is a no-op; the extension should still be in its initial state
        self::assertSame('pulsar/orm', $this->extension->name());
    }

    #[Test]
    public function bootDoesNotRegisterRoutes(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $router = $this->createMock(RouterInterface::class);

        // ORM does not register any routes
        $router->expects(self::never())->method('get');
        $router->expects(self::never())->method('post');

        $this->extension->boot($container, $router);
    }

    #[Test]
    public function providersReturnsOrmServiceProvider(): void
    {
        $providers = $this->extension->providers();

        self::assertCount(1, $providers);
        self::assertSame(OrmServiceProvider::class, $providers[0]);
    }
}
