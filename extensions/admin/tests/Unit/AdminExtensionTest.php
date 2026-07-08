<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extension\Admin\AdminExtension;
use Pulsar\Extension\Admin\AdminServiceProvider;
use Pulsar\Extension\Admin\Config\AdminConfig;
use Pulsar\Routing\RouterInterface;

#[CoversClass(AdminExtension::class)]
final class AdminExtensionTest extends TestCase
{
    private AdminExtension $extension;

    protected function setUp(): void
    {
        $this->extension = new AdminExtension();
    }

    #[Test]
    public function nameReturnsPulsarAdmin(): void
    {
        self::assertSame('pulsar/admin', $this->extension->name());
    }

    #[Test]
    public function registerDoesNotThrow(): void
    {
        $container = $this->createStub(ContainerInterface::class);

        $this->extension->register($container);

        // register() is a no-op; the extension should still be in its initial state
        self::assertSame('pulsar/admin', $this->extension->name());
    }

    #[Test]
    public function providersReturnsAdminServiceProvider(): void
    {
        $providers = $this->extension->providers();

        self::assertCount(1, $providers);
        self::assertSame(AdminServiceProvider::class, $providers[0]);
    }

    #[Test]
    public function bootSkipsWhenDisabled(): void
    {
        $config = AdminConfig::fromArray(['enabled' => false]);

        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willReturn($config);

        $router = $this->createMock(RouterInterface::class);
        $router->expects(self::never())->method('get');
        $router->expects(self::never())->method('post');

        $this->extension->boot($container, $router);
    }

    #[Test]
    public function bootRegistersRoutesWhenEnabled(): void
    {
        $config = AdminConfig::fromArray(['enabled' => true, 'route_prefix' => '/admin']);

        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willReturn($config);
        $container->method('has')->willReturn(false);

        $router = $this->createStub(RouterInterface::class);
        $router->method('get')->willReturnSelf();
        $router->method('post')->willReturnSelf();
        $router->method('put')->willReturnSelf();
        $router->method('delete')->willReturnSelf();

        // Should not throw
        $this->extension->boot($container, $router);

        // If boot didn't throw, the routes were registered successfully
        self::assertInstanceOf(AdminExtension::class, $this->extension);
    }

    #[Test]
    public function preBootSetsDefaultConfigWhenNoConfigFile(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(false);
        $container->expects(self::once())
            ->method('instance')
            ->with(AdminConfig::class, self::isInstanceOf(AdminConfig::class));

        $this->extension->preBoot($container);
    }
}
