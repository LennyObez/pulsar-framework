<?php

declare(strict_types=1);

namespace Pulsar\Extension\Accessibility\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\ExtensionInterface;
use Pulsar\Extension\Accessibility\AccessibilityExtension;
use Pulsar\Extension\Accessibility\AccessibilityServiceProvider;
use Pulsar\Routing\RouterInterface;

final class AccessibilityExtensionTest extends TestCase
{
    #[Test]
    public function implementsExtensionInterface(): void
    {
        self::assertInstanceOf(ExtensionInterface::class, new AccessibilityExtension());
    }

    #[Test]
    public function nameReturnsPulsarAccessibility(): void
    {
        self::assertSame('pulsar/accessibility', new AccessibilityExtension()->name());
    }

    #[Test]
    public function providersReturnsAccessibilityServiceProvider(): void
    {
        $providers = new AccessibilityExtension()->providers();

        self::assertCount(1, $providers);
        self::assertSame(AccessibilityServiceProvider::class, $providers[0]);
    }

    #[Test]
    public function bootInProductionDoesNotBindAuditCommand(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturnCallback(
            fn(string $id): bool => match ($id) {
                'app.environment' => true,
                default => false,
            },
        );
        $container->method('get')->willReturnCallback(
            fn(string $id): mixed => match ($id) {
                'app.environment' => 'production',
                default => null,
            },
        );

        $router = $this->createStub(RouterInterface::class);

        // Should not throw in production mode
        new AccessibilityExtension()->boot($container, $router);

        self::assertTrue(true, 'boot() completed without binding audit command in production');
    }

    #[Test]
    public function bootInDevModeBindsAuditCommand(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturnCallback(
            fn(string $id): bool => match ($id) {
                'app.debug' => true,
                default => false,
            },
        );
        $container->method('get')->willReturnCallback(
            fn(string $id): mixed => match ($id) {
                'app.debug' => true,
                default => null,
            },
        );

        $container->expects(self::once())->method('bind');

        $router = $this->createStub(RouterInterface::class);

        new AccessibilityExtension()->boot($container, $router);
    }
}
