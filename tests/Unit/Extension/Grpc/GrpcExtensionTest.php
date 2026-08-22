<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Grpc;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extension\Grpc\GrpcExtension;
use Pulsar\Extension\Grpc\GrpcServiceProvider;
use Pulsar\Routing\RouterInterface;

#[CoversClass(GrpcExtension::class)]
final class GrpcExtensionTest extends TestCase
{
    #[Test]
    public function nameReturnsPulsarGrpc(): void
    {
        $ext = new GrpcExtension();
        self::assertSame('pulsar/grpc', $ext->name());
    }

    #[Test]
    public function providersReturnsGrpcServiceProvider(): void
    {
        $ext = new GrpcExtension();
        self::assertSame([GrpcServiceProvider::class], $ext->providers());
    }

    #[Test]
    public function registerDoesNotThrow(): void
    {
        $this->expectNotToPerformAssertions();

        $ext = new GrpcExtension();
        $ext->register($this->createStub(ContainerInterface::class));
    }

    #[Test]
    public function bootDoesNotThrow(): void
    {
        $this->expectNotToPerformAssertions();

        $ext = new GrpcExtension();
        $ext->boot($this->createStub(ContainerInterface::class), $this->createStub(RouterInterface::class));
    }
}
