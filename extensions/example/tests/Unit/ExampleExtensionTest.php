<?php

declare(strict_types=1);

namespace Pulsar\Extension\Example\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extension\Example\ExampleExtension;
use Pulsar\Extension\Example\ExampleServiceProvider;
use Pulsar\Routing\RouterInterface;

#[CoversClass(ExampleExtension::class)]
final class ExampleExtensionTest extends TestCase
{
    private ExampleExtension $extension;

    protected function setUp(): void
    {
        $this->extension = new ExampleExtension();
    }

    #[Test]
    public function nameReturnsPulsarExample(): void
    {
        self::assertSame('pulsar/example', $this->extension->name());
    }

    #[Test]
    public function providersReturnsExampleServiceProvider(): void
    {
        $providers = $this->extension->providers();

        self::assertCount(1, $providers);
        self::assertSame(ExampleServiceProvider::class, $providers[0]);
    }

    #[Test]
    public function bootRegistersRoutes(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $router = $this->createMock(RouterInterface::class);

        $router->expects(self::exactly(3))
            ->method('get');

        $this->extension->boot($container, $router);
    }
}
