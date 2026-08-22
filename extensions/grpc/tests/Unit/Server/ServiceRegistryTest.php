<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Tests\Unit\Server;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Grpc\Handler\MethodDescriptor;
use Pulsar\Extension\Grpc\Handler\MethodType;
use Pulsar\Extension\Grpc\Handler\ServiceHandlerInterface;
use Pulsar\Extension\Grpc\Server\ServiceRegistry;

#[CoversClass(ServiceRegistry::class)]
final class ServiceRegistryTest extends TestCase
{
    #[Test]
    public function is_empty_when_no_services_registered(): void
    {
        $registry = new ServiceRegistry();

        self::assertTrue($registry->isEmpty());
        self::assertSame([], $registry->serviceNames());
    }

    #[Test]
    public function register_adds_service_and_methods(): void
    {
        $registry = new ServiceRegistry();
        $handler = $this->createGreeterHandler();

        $registry->register($handler);

        self::assertFalse($registry->isEmpty());
        self::assertSame(['helloworld.Greeter'], $registry->serviceNames());
    }

    #[Test]
    public function resolve_method_returns_descriptor_for_registered_method(): void
    {
        $registry = new ServiceRegistry();
        $handler = $this->createGreeterHandler();

        $registry->register($handler);

        $method = $registry->resolveMethod('/helloworld.Greeter/SayHello');

        self::assertNotNull($method);
        self::assertSame('SayHello', $method->name);
        self::assertSame(MethodType::Unary, $method->type);
    }

    #[Test]
    public function resolve_method_returns_null_for_unknown_method(): void
    {
        $registry = new ServiceRegistry();
        $handler = $this->createGreeterHandler();

        $registry->register($handler);

        self::assertNull($registry->resolveMethod('/helloworld.Greeter/Unknown'));
        self::assertNull($registry->resolveMethod('/unknown.Service/Method'));
    }

    #[Test]
    public function resolve_handler_returns_handler_for_registered_method(): void
    {
        $registry = new ServiceRegistry();
        $handler = $this->createGreeterHandler();

        $registry->register($handler);

        $resolved = $registry->resolveHandler('/helloworld.Greeter/SayHello');

        self::assertSame($handler, $resolved);
    }

    #[Test]
    public function resolve_handler_returns_null_for_unknown_method(): void
    {
        $registry = new ServiceRegistry();

        self::assertNull($registry->resolveHandler('/unknown.Service/Method'));
    }

    #[Test]
    public function resolve_returns_both_method_and_handler(): void
    {
        $registry = new ServiceRegistry();
        $handler = $this->createGreeterHandler();
        $registry->register($handler);

        $result = $registry->resolve('/helloworld.Greeter/SayHello');

        self::assertNotNull($result);
        [$method, $resolvedHandler] = $result;
        self::assertSame('SayHello', $method->name);
        self::assertSame($handler, $resolvedHandler);
    }

    #[Test]
    public function resolve_returns_null_for_unknown_method(): void
    {
        $registry = new ServiceRegistry();

        self::assertNull($registry->resolve('/unknown.Service/Method'));
    }

    #[Test]
    public function methods_for_service_returns_methods_of_registered_service(): void
    {
        $registry = new ServiceRegistry();
        $handler = $this->createGreeterHandler();

        $registry->register($handler);

        $methods = $registry->methodsForService('helloworld.Greeter');

        self::assertCount(2, $methods);
        self::assertArrayHasKey('SayHello', $methods);
        self::assertArrayHasKey('SayHelloStream', $methods);
    }

    #[Test]
    public function methods_for_service_returns_empty_for_unknown_service(): void
    {
        $registry = new ServiceRegistry();

        self::assertSame([], $registry->methodsForService('unknown.Service'));
    }

    #[Test]
    public function register_multiple_services(): void
    {
        $registry = new ServiceRegistry();

        $greeter = $this->createGreeterHandler();
        $echo = $this->createEchoHandler();

        $registry->register($greeter);
        $registry->register($echo);

        self::assertSame(['helloworld.Greeter', 'echo.Echo'], $registry->serviceNames());

        self::assertNotNull($registry->resolveMethod('/helloworld.Greeter/SayHello'));
        self::assertNotNull($registry->resolveMethod('/echo.Echo/Echo'));

        self::assertSame($greeter, $registry->resolveHandler('/helloworld.Greeter/SayHello'));
        self::assertSame($echo, $registry->resolveHandler('/echo.Echo/Echo'));
    }

    #[Test]
    public function service_names_are_returned_in_registration_order(): void
    {
        $registry = new ServiceRegistry();

        $registry->register($this->createEchoHandler());
        $registry->register($this->createGreeterHandler());

        self::assertSame(['echo.Echo', 'helloworld.Greeter'], $registry->serviceNames());
    }

    private function createGreeterHandler(): ServiceHandlerInterface
    {
        $handler = $this->createStub(ServiceHandlerInterface::class);
        $handler->method('serviceName')->willReturn('helloworld.Greeter');
        $handler->method('methods')->willReturn([
            'SayHello' => new MethodDescriptor(
                name: 'SayHello',
                fullName: '/helloworld.Greeter/SayHello',
                type: MethodType::Unary,
                inputType: 'helloworld.HelloRequest',
                outputType: 'helloworld.HelloReply',
                handler: 'GreeterService::sayHello',
            ),
            'SayHelloStream' => new MethodDescriptor(
                name: 'SayHelloStream',
                fullName: '/helloworld.Greeter/SayHelloStream',
                type: MethodType::ServerStreaming,
                inputType: 'helloworld.HelloRequest',
                outputType: 'helloworld.HelloReply',
                handler: 'GreeterService::sayHelloStream',
            ),
        ]);

        return $handler;
    }

    private function createEchoHandler(): ServiceHandlerInterface
    {
        $handler = $this->createStub(ServiceHandlerInterface::class);
        $handler->method('serviceName')->willReturn('echo.Echo');
        $handler->method('methods')->willReturn([
            'Echo' => new MethodDescriptor(
                name: 'Echo',
                fullName: '/echo.Echo/Echo',
                type: MethodType::Unary,
                inputType: 'echo.EchoRequest',
                outputType: 'echo.EchoResponse',
                handler: 'EchoService::echo',
            ),
        ]);

        return $handler;
    }
}
