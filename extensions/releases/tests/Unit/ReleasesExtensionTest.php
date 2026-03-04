<?php

declare(strict_types=1);

namespace Pulsar\Extension\Releases\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\ExtensionInterface;
use Pulsar\Extension\Releases\ReleasesExtension;
use Pulsar\Extension\Releases\ReleasesServiceProvider;
use Pulsar\Routing\RouterInterface;

final class ReleasesExtensionTest extends TestCase
{
    #[Test]
    public function implementsExtensionInterface(): void
    {
        self::assertInstanceOf(ExtensionInterface::class, new ReleasesExtension());
    }

    #[Test]
    public function nameReturnsPulsarReleases(): void
    {
        self::assertSame('pulsar/releases', new ReleasesExtension()->name());
    }

    #[Test]
    public function providersReturnsReleasesServiceProvider(): void
    {
        $providers = new ReleasesExtension()->providers();

        self::assertCount(1, $providers);
        self::assertSame(ReleasesServiceProvider::class, $providers[0]);
    }

    #[Test]
    public function bootRegistersApiAndAdminRoutes(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $router = $this->createMock(RouterInterface::class);

        // 5 GET routes + 1 POST (beta signup + admin store)
        $router->expects(self::exactly(5))->method('get');
        $router->expects(self::exactly(2))->method('post');
        $router->expects(self::once())->method('put');

        $ext = new ReleasesExtension();
        $ext->boot($container, $router);
    }
}
