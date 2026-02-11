<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Example;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\Container;
use Pulsar\Extension\Example\ExampleExtension;
use Pulsar\Extension\Example\ExampleServiceProvider;
use Pulsar\Routing\Router;

#[CoversClass(ExampleExtension::class)]
final class ExampleExtensionTest extends TestCase
{
    #[Test]
    public function nameReturnsPulsarExample(): void
    {
        $extension = new ExampleExtension();

        self::assertSame('pulsar/example', $extension->name());
    }

    #[Test]
    public function providersReturnsExampleServiceProvider(): void
    {
        $extension = new ExampleExtension();

        self::assertSame([ExampleServiceProvider::class], $extension->providers());
    }

    #[Test]
    public function bootRegistersThreeRoutes(): void
    {
        $extension = new ExampleExtension();
        $container = new Container();
        $router = new Router();

        $extension->boot($container, $router);

        self::assertSame(3, $router->count());
    }
}
