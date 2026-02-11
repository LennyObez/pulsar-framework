<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Accessibility;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extension\Accessibility\AccessibilityExtension;
use Pulsar\Extension\Accessibility\AccessibilityServiceProvider;
use Pulsar\Routing\RouterInterface;

#[CoversClass(AccessibilityExtension::class)]
final class AccessibilityExtensionTest extends TestCase
{
    #[Test]
    public function nameReturnsPulsarAccessibility(): void
    {
        $ext = new AccessibilityExtension();
        self::assertSame('pulsar/accessibility', $ext->name());
    }

    #[Test]
    public function providersReturnsAccessibilityServiceProvider(): void
    {
        $ext = new AccessibilityExtension();
        self::assertSame([AccessibilityServiceProvider::class], $ext->providers());
    }

    #[Test]
    public function registerDoesNotThrow(): void
    {
        $this->expectNotToPerformAssertions();

        $ext = new AccessibilityExtension();
        $ext->register($this->createStub(ContainerInterface::class));
    }

    #[Test]
    public function bootRegistersCommandInDevMode(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')
            ->willReturnCallback(static fn(string $id): bool => $id === 'app.debug');
        $container->method('get')
            ->willReturnCallback(static fn(string $id): mixed => $id === 'app.debug' ? true : null);
        $container->expects(self::once())->method('bind');

        $router = $this->createStub(RouterInterface::class);

        $ext = new AccessibilityExtension();
        $ext->boot($container, $router);
    }

    #[Test]
    public function bootSkipsCommandInProductionMode(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')
            ->willReturnCallback(static fn(string $id): bool => $id === 'app.environment');
        $container->method('get')
            ->willReturnCallback(static fn(string $id): mixed => $id === 'app.environment' ? 'production' : null);
        $container->expects(self::never())->method('bind');

        $router = $this->createStub(RouterInterface::class);

        $ext = new AccessibilityExtension();
        $ext->boot($container, $router);
    }

    #[Test]
    public function bootSkipsCommandWhenProdEnvironment(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')
            ->willReturnCallback(static fn(string $id): bool => $id === 'app.environment');
        $container->method('get')
            ->willReturnCallback(static fn(string $id): mixed => $id === 'app.environment' ? 'prod' : null);
        $container->expects(self::never())->method('bind');

        $router = $this->createStub(RouterInterface::class);

        $ext = new AccessibilityExtension();
        $ext->boot($container, $router);
    }

    #[Test]
    public function bootSkipsCommandWithNoDebugOrEnvironment(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(false);
        $container->expects(self::never())->method('bind');

        $router = $this->createStub(RouterInterface::class);

        $ext = new AccessibilityExtension();
        $ext->boot($container, $router);
    }

    #[Test]
    public function bootRegistersCommandInDevelopmentEnvironment(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')
            ->willReturnCallback(static fn(string $id): bool => $id === 'app.environment');
        $container->method('get')
            ->willReturnCallback(static fn(string $id): mixed => $id === 'app.environment' ? 'development' : null);
        $container->expects(self::once())->method('bind');

        $router = $this->createStub(RouterInterface::class);

        $ext = new AccessibilityExtension();
        $ext->boot($container, $router);
    }
}
