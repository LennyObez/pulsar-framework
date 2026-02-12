<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psr7Bridge\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\ExtensionInterface;
use Pulsar\Extension\Psr7Bridge\Psr7BridgeExtension;
use Pulsar\Routing\RouterInterface;

final class Psr7BridgeExtensionTest extends TestCase
{
    #[Test]
    public function implementsExtensionInterface(): void
    {
        self::assertInstanceOf(ExtensionInterface::class, new Psr7BridgeExtension());
    }

    #[Test]
    public function nameReturnsPulsarPsr7Bridge(): void
    {
        self::assertSame('pulsar/psr7-bridge', new Psr7BridgeExtension()->name());
    }

    #[Test]
    public function registerIsNoOp(): void
    {
        $ext = new Psr7BridgeExtension();
        $container = $this->createStub(ContainerInterface::class);

        $ext->register($container);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function bootIsNoOp(): void
    {
        $ext = new Psr7BridgeExtension();
        $container = $this->createStub(ContainerInterface::class);
        $router = $this->createStub(RouterInterface::class);

        $ext->boot($container, $router);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function providersReturnsEmptyArray(): void
    {
        self::assertSame([], new Psr7BridgeExtension()->providers());
    }
}
