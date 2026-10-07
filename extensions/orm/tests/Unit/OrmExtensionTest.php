<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\ExtensionInterface;
use Pulsar\Extension\Orm\OrmExtension;
use Pulsar\Extension\Orm\OrmServiceProvider;
use Pulsar\Routing\RouterInterface;

final class OrmExtensionTest extends TestCase
{
    #[Test]
    public function implementsExtensionInterface(): void
    {
        self::assertInstanceOf(ExtensionInterface::class, new OrmExtension());
    }

    #[Test]
    public function nameReturnsPulsarOrm(): void
    {
        self::assertSame('pulsar/orm', new OrmExtension()->name());
    }

    #[Test]
    public function providersReturnsOrmServiceProvider(): void
    {
        $providers = new OrmExtension()->providers();

        self::assertCount(1, $providers);
        self::assertSame(OrmServiceProvider::class, $providers[0]);
    }

    #[Test]
    public function bootDoesNotRegisterRoutes(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $router = $this->createMock(RouterInterface::class);

        $router->expects(self::never())->method('get');
        $router->expects(self::never())->method('post');

        new OrmExtension()->boot($container, $router);
    }
}
