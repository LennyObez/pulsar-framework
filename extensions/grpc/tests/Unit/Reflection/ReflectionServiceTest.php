<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Tests\Unit\Reflection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Grpc\Handler\MethodDescriptor;
use Pulsar\Extension\Grpc\Handler\MethodType;
use Pulsar\Extension\Grpc\Reflection\ReflectionService;
use Pulsar\Extension\Grpc\Server\ServiceRegistryInterface;

#[CoversClass(ReflectionService::class)]
final class ReflectionServiceTest extends TestCase
{
    #[Test]
    public function listServicesReturnsAllRegisteredServiceNames(): void
    {
        $registry = $this->createStub(ServiceRegistryInterface::class);
        $registry->method('serviceNames')->willReturn(['helloworld.Greeter', 'billing.Invoice']);

        $service = new ReflectionService($registry);

        self::assertSame(['helloworld.Greeter', 'billing.Invoice'], $service->listServices());
    }

    #[Test]
    public function listServicesReturnsEmptyArrayWhenNoServicesRegistered(): void
    {
        $registry = $this->createStub(ServiceRegistryInterface::class);
        $registry->method('serviceNames')->willReturn([]);

        $service = new ReflectionService($registry);

        self::assertSame([], $service->listServices());
    }

    #[Test]
    public function methodsForServiceReturnsMethods(): void
    {
        $descriptor = new MethodDescriptor(
            name: 'SayHello',
            fullName: '/helloworld.Greeter/SayHello',
            type: MethodType::Unary,
            inputType: 'helloworld.HelloRequest',
            outputType: 'helloworld.HelloReply',
            handler: 'App\Grpc\GreeterHandler::sayHello',
        );

        $registry = $this->createStub(ServiceRegistryInterface::class);
        $registry->method('methodsForService')
            ->willReturn(['SayHello' => $descriptor]);

        $service = new ReflectionService($registry);
        $methods = $service->methodsForService('helloworld.Greeter');

        self::assertCount(1, $methods);
        self::assertArrayHasKey('SayHello', $methods);
        self::assertSame('SayHello', $methods['SayHello']->name);
        self::assertSame(MethodType::Unary, $methods['SayHello']->type);
    }

    #[Test]
    public function methodsForServiceReturnsEmptyArrayForUnknownService(): void
    {
        $registry = $this->createStub(ServiceRegistryInterface::class);
        $registry->method('methodsForService')->willReturn([]);

        $service = new ReflectionService($registry);

        self::assertSame([], $service->methodsForService('unknown.Service'));
    }

    #[Test]
    public function methodsForServiceReturnsMultipleMethods(): void
    {
        $sayHello = new MethodDescriptor(
            name: 'SayHello',
            fullName: '/helloworld.Greeter/SayHello',
            type: MethodType::Unary,
            inputType: 'helloworld.HelloRequest',
            outputType: 'helloworld.HelloReply',
            handler: 'App\Grpc\GreeterHandler::sayHello',
        );

        $sayHelloStream = new MethodDescriptor(
            name: 'SayHelloStream',
            fullName: '/helloworld.Greeter/SayHelloStream',
            type: MethodType::ServerStreaming,
            inputType: 'helloworld.HelloRequest',
            outputType: 'helloworld.HelloReply',
            handler: 'App\Grpc\GreeterHandler::sayHelloStream',
        );

        $registry = $this->createStub(ServiceRegistryInterface::class);
        $registry->method('methodsForService')
            ->willReturn(['SayHello' => $sayHello, 'SayHelloStream' => $sayHelloStream]);

        $service = new ReflectionService($registry);
        $methods = $service->methodsForService('helloworld.Greeter');

        self::assertCount(2, $methods);
        self::assertArrayHasKey('SayHello', $methods);
        self::assertArrayHasKey('SayHelloStream', $methods);
    }
}
