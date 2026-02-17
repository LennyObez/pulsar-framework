<?php

declare(strict_types=1);

namespace Pulsar\Extension\Graphql\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\ExtensionInterface;
use Pulsar\Extension\Graphql\GraphqlExtension;
use Pulsar\Extension\Graphql\GraphqlServiceProvider;
use Pulsar\Extension\Graphql\Http\GraphqlController;
use Pulsar\Routing\RouterInterface;

final class GraphqlExtensionTest extends TestCase
{
    #[Test]
    public function implementsExtensionInterface(): void
    {
        self::assertInstanceOf(ExtensionInterface::class, new GraphqlExtension());
    }

    #[Test]
    public function nameReturnsPulsarGraphql(): void
    {
        self::assertSame('pulsar/graphql', new GraphqlExtension()->name());
    }

    #[Test]
    public function providersReturnsGraphqlServiceProvider(): void
    {
        $providers = new GraphqlExtension()->providers();

        self::assertCount(1, $providers);
        self::assertSame(GraphqlServiceProvider::class, $providers[0]);
    }

    #[Test]
    public function bootRegistersRoutesWhenControllerAvailable(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')
            ->willReturnCallback(fn(string $id): bool => $id === GraphqlController::class);

        $router = $this->createMock(RouterInterface::class);
        $router->expects(self::once())->method('post');
        $router->expects(self::once())->method('get');

        new GraphqlExtension()->boot($container, $router);
    }

    #[Test]
    public function bootSkipsRoutesWhenControllerNotAvailable(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturn(false);

        $router = $this->createMock(RouterInterface::class);
        $router->expects(self::never())->method('post');
        $router->expects(self::never())->method('get');

        new GraphqlExtension()->boot($container, $router);
    }
}
