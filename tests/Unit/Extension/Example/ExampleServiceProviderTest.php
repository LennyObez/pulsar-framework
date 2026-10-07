<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Example;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\Container;
use Pulsar\Extension\Example\ExampleService;
use Pulsar\Extension\Example\ExampleServiceProvider;

final class ExampleServiceProviderTest extends TestCase
{
    #[Test]
    public function registerBindsExampleService(): void
    {
        $provider = new ExampleServiceProvider();
        $container = new Container();

        $provider->register($container);

        self::assertTrue($container->has(ExampleService::class));
    }

    #[Test]
    public function providesReturnsExampleServiceClass(): void
    {
        $provider = new ExampleServiceProvider();

        self::assertSame([ExampleService::class], $provider->provides());
    }
}
