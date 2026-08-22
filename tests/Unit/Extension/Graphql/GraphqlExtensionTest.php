<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Graphql;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extension\Graphql\GraphqlExtension;
use Pulsar\Extension\Graphql\GraphqlServiceProvider;
use Pulsar\Routing\RouterInterface;

#[CoversClass(GraphqlExtension::class)]
final class GraphqlExtensionTest extends TestCase
{
    #[Test]
    public function nameReturnsPulsarGraphql(): void
    {
        $ext = new GraphqlExtension();
        self::assertSame('pulsar/graphql', $ext->name());
    }

    #[Test]
    public function providersReturnsGraphqlServiceProvider(): void
    {
        $ext = new GraphqlExtension();
        self::assertSame([GraphqlServiceProvider::class], $ext->providers());
    }

    #[Test]
    public function registerDoesNotThrow(): void
    {
        $this->expectNotToPerformAssertions();

        $ext = new GraphqlExtension();
        $ext->register($this->createStub(ContainerInterface::class));
    }

    #[Test]
    public function bootRegistersRoutesWhenControllerAvailable(): void
    {
        $this->expectNotToPerformAssertions();

        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturn(true);

        $router = $this->createStub(RouterInterface::class);
        $router->method('post')->willReturnSelf();
        $router->method('get')->willReturnSelf();

        $ext = new GraphqlExtension();
        $ext->boot($container, $router);
    }

    #[Test]
    public function bootSkipsRoutesWhenControllerNotAvailable(): void
    {
        $this->expectNotToPerformAssertions();

        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturn(false);

        $router = $this->createStub(RouterInterface::class);

        $ext = new GraphqlExtension();
        $ext->boot($container, $router);
    }
}
