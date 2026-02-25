<?php

declare(strict_types=1);

namespace Pulsar\Extension\Example\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extension\Example\ExampleService;
use Pulsar\Extension\Example\ExampleServiceProvider;

#[CoversClass(ExampleServiceProvider::class)]
final class ExampleServiceProviderTest extends TestCase
{
    #[Test]
    public function registerBindsExampleService(): void
    {
        $container = $this->createMock(ContainerInterface::class);

        $container->expects(self::once())
            ->method('bind')
            ->with(ExampleService::class, ExampleService::class);

        $provider = new ExampleServiceProvider();
        $provider->register($container);
    }

    #[Test]
    public function providesListsExampleService(): void
    {
        $provider = new ExampleServiceProvider();

        self::assertSame([ExampleService::class], $provider->provides());
    }
}
