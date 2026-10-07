<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Forum;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\ConfigManagerInterface;
use Pulsar\Container\ContainerInterface;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Event\ListenerProviderInterface;
use Pulsar\Extensibility\ExtensionInterface;
use Pulsar\Extensibility\PostBootExtensionInterface;
use Pulsar\Extensibility\PreBootExtensionInterface;
use Pulsar\Extension\Admin\Gateway\AdminGateway;
use Pulsar\Extension\Cms\Dashboard\DashboardWidgetInterface;
use Pulsar\Extension\Forum\Config\ForumConfig;
use Pulsar\Extension\Forum\ForumExtension;
use Pulsar\Extension\Forum\ForumServiceProvider;
use Pulsar\Extension\Forum\Internal\Notification\ForumNotificationDispatcher;
use Pulsar\Routing\RouterInterface;

#[CoversClass(ForumExtension::class)]
final class ForumExtensionTest extends TestCase
{
    private ForumExtension $extension;
    private ContainerInterface&Stub $container;
    private RouterInterface&Stub $router;

    protected function setUp(): void
    {
        $this->extension = new ForumExtension();
        $this->container = $this->createStub(ContainerInterface::class);
        $this->router = $this->createStub(RouterInterface::class);
        $this->router->method('get')->willReturnSelf();
        $this->router->method('post')->willReturnSelf();
        $this->router->method('put')->willReturnSelf();
        $this->router->method('delete')->willReturnSelf();
    }

    #[Test]
    public function implementsExtensionInterface(): void
    {
        self::assertInstanceOf(ExtensionInterface::class, $this->extension);
    }

    #[Test]
    public function implementsPreBootExtensionInterface(): void
    {
        self::assertInstanceOf(PreBootExtensionInterface::class, $this->extension);
    }

    #[Test]
    public function implementsPostBootExtensionInterface(): void
    {
        self::assertInstanceOf(PostBootExtensionInterface::class, $this->extension);
    }

    #[Test]
    public function nameReturnsPulsarForum(): void
    {
        self::assertSame('pulsar/forum', $this->extension->name());
    }

    #[Test]
    public function providersReturnsForumServiceProvider(): void
    {
        $providers = $this->extension->providers();

        self::assertCount(1, $providers);
        self::assertSame(ForumServiceProvider::class, $providers[0]);
    }

    #[Test]
    public function registerDoesNotThrow(): void
    {
        $this->expectNotToPerformAssertions();

        $this->extension->register($this->container);
    }

    #[Test]
    public function preBootRegistersDefaultConfigWhenNoneExists(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturnCallback(static fn(string $id): bool => match ($id) {
            ForumConfig::class => false,
            ConfigManagerInterface::class => false,
            default => false,
        });

        $container->expects(self::once())->method('instance')
            ->with(ForumConfig::class, self::isInstanceOf(ForumConfig::class));

        $this->extension->preBoot($container);
    }

    #[Test]
    public function preBootSkipsWhenForumConfigAlreadyExists(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturnCallback(static fn(string $id): bool => match ($id) {
            ForumConfig::class => true,
            default => false,
        });

        $container->expects(self::never())->method('instance');

        $this->extension->preBoot($container);
    }

    #[Test]
    public function bootRegistersRoutes(): void
    {
        $router = $this->createMock(RouterInterface::class);
        $router->method('get')->willReturnSelf();
        $router->method('post')->willReturnSelf();
        $router->method('put')->willReturnSelf();
        $router->method('delete')->willReturnSelf();

        // At minimum, some GET routes should be registered
        $router->expects(self::atLeast(5))->method('get');

        $this->extension->boot($this->container, $router);
    }

    #[Test]
    public function postBootSkipsAdminResourcesWhenNoAdminGateway(): void
    {
        $this->expectNotToPerformAssertions();

        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturn(false);

        $this->extension->postBoot($container);
    }

    #[Test]
    public function postBootSkipsCmsWidgetsWhenNoDashboardWidget(): void
    {
        $this->expectNotToPerformAssertions();

        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturnCallback(static fn(string $id): bool => match ($id) {
            AdminGateway::class => false,
            DashboardWidgetInterface::class => false,
            default => false,
        });

        $this->extension->postBoot($container);
    }

    #[Test]
    public function postBootRegistersCmsWidgetsWhenDependenciesAvailable(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $container = $this->createMock(ContainerInterface::class);

        $container->method('has')->willReturnCallback(static fn(string $id): bool => match ($id) {
            AdminGateway::class => false,
            DashboardWidgetInterface::class, ConnectionInterface::class => true,
            ForumNotificationDispatcher::class, ListenerProviderInterface::class => false,
            default => false,
        });
        $container->method('get')->willReturnCallback(static fn(string $id) => match ($id) {
            ConnectionInterface::class => $connection,
            default => null,
        });

        // Should call instance() to register CMS widget
        $container->expects(self::atLeastOnce())->method('instance');

        $this->extension->postBoot($container);
    }

    #[Test]
    public function postBootSkipsNotificationListenersWhenDispatcherMissing(): void
    {
        $this->expectNotToPerformAssertions();

        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturnCallback(static fn(string $id): bool => match ($id) {
            AdminGateway::class, DashboardWidgetInterface::class => false,
            ForumNotificationDispatcher::class => false,
            ListenerProviderInterface::class => true,
            default => false,
        });

        $this->extension->postBoot($container);
    }

    #[Test]
    public function postBootSkipsNotificationListenersWhenListenerProviderMissing(): void
    {
        $this->expectNotToPerformAssertions();

        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturnCallback(static fn(string $id): bool => match ($id) {
            AdminGateway::class, DashboardWidgetInterface::class => false,
            ForumNotificationDispatcher::class => true,
            ListenerProviderInterface::class => false,
            default => false,
        });

        $this->extension->postBoot($container);
    }
}
