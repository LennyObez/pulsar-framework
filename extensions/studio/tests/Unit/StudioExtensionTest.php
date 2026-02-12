<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\ExtensionInterface;
use Pulsar\Extension\Studio\StudioExtension;
use Pulsar\Routing\RouterInterface;

final class StudioExtensionTest extends TestCase
{
    #[Test]
    public function implementsExtensionInterface(): void
    {
        self::assertInstanceOf(ExtensionInterface::class, new StudioExtension());
    }

    #[Test]
    public function nameReturnsPulsarStudio(): void
    {
        self::assertSame('pulsar/studio', new StudioExtension()->name());
    }

    #[Test]
    public function providersReturnsEmptyArray(): void
    {
        self::assertSame([], new StudioExtension()->providers());
    }

    #[Test]
    public function bootIsNoOpWithoutStudioRoutes(): void
    {
        $ext = new StudioExtension();
        $container = $this->createStub(ContainerInterface::class);
        $router = $this->createStub(RouterInterface::class);

        $ext->boot($container, $router);
        $this->addToAssertionCount(1);
    }
}
