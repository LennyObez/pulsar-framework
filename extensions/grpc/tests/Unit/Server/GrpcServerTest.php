<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Tests\Unit\Server;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Grpc\Adapter\GrpcRequestHandler;
use Pulsar\Extension\Grpc\Adapter\GrpcTransportAdapterInterface;
use Pulsar\Extension\Grpc\Config\GrpcConfig;
use Pulsar\Extension\Grpc\Error\GrpcException;
use Pulsar\Extension\Grpc\Error\GrpcStatus;
use Pulsar\Extension\Grpc\Handler\MethodDescriptor;
use Pulsar\Extension\Grpc\Handler\MethodType;
use Pulsar\Extension\Grpc\Handler\ServiceHandlerInterface;
use Pulsar\Extension\Grpc\Interceptor\InterceptorPipeline;
use Pulsar\Extension\Grpc\Server\GrpcServer;
use Pulsar\Extension\Grpc\Server\RequestDispatcher;
use Pulsar\Extension\Grpc\Server\ServiceRegistry;
use Pulsar\Extension\Grpc\Server\ServiceRegistryInterface;
use RuntimeException;

#[CoversClass(GrpcServer::class)]
#[CoversClass(RequestDispatcher::class)]
final class GrpcServerTest extends TestCase
{
    #[Test]
    public function start_throws_when_no_services_registered(): void
    {
        $config = new GrpcConfig();
        $registry = new ServiceRegistry();
        $adapter = $this->createStub(GrpcTransportAdapterInterface::class);
        $pipeline = new InterceptorPipeline();

        $server = new GrpcServer($config, $registry, $adapter, $pipeline);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('no services registered');

        $server->start();
    }

    #[Test]
    public function start_throws_when_adapter_not_available(): void
    {
        $config = new GrpcConfig();
        $registry = $this->createRegistryWithGreeter();

        $adapter = $this->createStub(GrpcTransportAdapterInterface::class);
        $adapter->method('isAvailable')->willReturn(false);
        $adapter->method('name')->willReturn('test_adapter');

        $pipeline = new InterceptorPipeline();

        $server = new GrpcServer($config, $registry, $adapter, $pipeline);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('not available');

        $server->start();
    }

    #[Test]
    public function start_calls_adapter_listen(): void
    {
        $config = new GrpcConfig(host: '127.0.0.1', port: 9090);
        $registry = $this->createRegistryWithGreeter();

        $adapter = $this->createMock(GrpcTransportAdapterInterface::class);
        $adapter->method('isAvailable')->willReturn(true);
        $adapter->expects(self::once())
            ->method('listen')
            ->with('127.0.0.1', 9090, self::isInstanceOf(GrpcRequestHandler::class));

        $pipeline = new InterceptorPipeline();

        $server = new GrpcServer($config, $registry, $adapter, $pipeline);
        $server->start();
    }

    #[Test]
    public function stop_calls_adapter_shutdown(): void
    {
        $config = new GrpcConfig();
        $registry = new ServiceRegistry();

        $adapter = $this->createMock(GrpcTransportAdapterInterface::class);
        $adapter->expects(self::once())->method('shutdown');

        $pipeline = new InterceptorPipeline();

        $server = new GrpcServer($config, $registry, $adapter, $pipeline);
        $server->stop();
    }

    #[Test]
    public function handle_returns_unimplemented_for_unknown_method(): void
    {
        $config = new GrpcConfig();
        $registry = new ServiceRegistry();
        $adapter = $this->createStub(GrpcTransportAdapterInterface::class);
        $pipeline = new InterceptorPipeline();

        $server = new GrpcServer($config, $registry, $adapter, $pipeline);

        $result = $server->handle('/unknown.Service/Method', '');

        self::assertSame(GrpcStatus::Unimplemented, $result->status);
        self::assertSame('Method not implemented', $result->message);
    }

    #[Test]
    public function handle_does_not_leak_method_name_in_error(): void
    {
        $config = new GrpcConfig();
        $registry = new ServiceRegistry();
        $adapter = $this->createStub(GrpcTransportAdapterInterface::class);
        $pipeline = new InterceptorPipeline();

        $server = new GrpcServer($config, $registry, $adapter, $pipeline);

        $result = $server->handle('/secret.Internal/AdminMethod', '');

        self::assertStringNotContainsString('secret.Internal', $result->message);
        self::assertStringNotContainsString('AdminMethod', $result->message);
    }

    #[Test]
    public function handle_dispatches_to_service_handler(): void
    {
        $config = new GrpcConfig();
        $registry = $this->createRegistryWithGreeter('response-payload');
        $adapter = $this->createStub(GrpcTransportAdapterInterface::class);
        $pipeline = new InterceptorPipeline();

        $server = new GrpcServer($config, $registry, $adapter, $pipeline);

        $result = $server->handle(
            '/helloworld.Greeter/SayHello',
            'request-payload',
            ['content-type' => ['application/grpc']],
        );

        self::assertTrue($result->isOk());
        self::assertSame('response-payload', $result->payload);
    }

    #[Test]
    public function handle_parses_grpc_timeout_in_seconds(): void
    {
        $config = new GrpcConfig();
        $registry = $this->createRegistryWithGreeter('ok');
        $adapter = $this->createStub(GrpcTransportAdapterInterface::class);
        $pipeline = new InterceptorPipeline();

        $server = new GrpcServer($config, $registry, $adapter, $pipeline);

        // A very large timeout ensures the deadline is in the future.
        $result = $server->handle(
            '/helloworld.Greeter/SayHello',
            'payload',
            ['grpc-timeout' => ['3600S']],
        );

        self::assertTrue($result->isOk());
    }

    #[Test]
    public function handle_parses_grpc_timeout_in_milliseconds(): void
    {
        $config = new GrpcConfig();
        $registry = $this->createRegistryWithGreeter('ok');
        $adapter = $this->createStub(GrpcTransportAdapterInterface::class);
        $pipeline = new InterceptorPipeline();

        $server = new GrpcServer($config, $registry, $adapter, $pipeline);

        $result = $server->handle(
            '/helloworld.Greeter/SayHello',
            'payload',
            ['grpc-timeout' => ['5000m']],
        );

        self::assertTrue($result->isOk());
    }

    #[Test]
    public function handle_unknown_timeout_unit_returns_null_deadline(): void
    {
        $config = new GrpcConfig();
        $registry = $this->createRegistryWithGreeter('ok');
        $adapter = $this->createStub(GrpcTransportAdapterInterface::class);
        $pipeline = new InterceptorPipeline();

        $server = new GrpcServer($config, $registry, $adapter, $pipeline);

        // 'x' is not a valid gRPC timeout unit; should not crash
        $result = $server->handle(
            '/helloworld.Greeter/SayHello',
            'payload',
            ['grpc-timeout' => ['100x']],
        );

        self::assertTrue($result->isOk());
    }

    #[Test]
    public function handle_with_peer_identity(): void
    {
        $config = new GrpcConfig();
        $registry = $this->createRegistryWithGreeter('ok');
        $adapter = $this->createStub(GrpcTransportAdapterInterface::class);
        $pipeline = new InterceptorPipeline();

        $server = new GrpcServer($config, $registry, $adapter, $pipeline);

        $result = $server->handle(
            '/helloworld.Greeter/SayHello',
            'payload',
            [],
            'client.example.com',
        );

        self::assertTrue($result->isOk());
    }

    #[Test]
    public function handle_catches_grpc_exception_from_handler(): void
    {
        $config = new GrpcConfig();

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
        ]);
        $handler->method('invoke')->willThrowException(
            new GrpcException(
                GrpcStatus::PermissionDenied,
                'Access denied',
            ),
        );

        $registry = new ServiceRegistry();
        $registry->register($handler);

        $adapter = $this->createStub(GrpcTransportAdapterInterface::class);
        $pipeline = new InterceptorPipeline();

        $server = new GrpcServer($config, $registry, $adapter, $pipeline);

        $result = $server->handle('/helloworld.Greeter/SayHello', 'payload');

        self::assertSame(GrpcStatus::PermissionDenied, $result->status);
        self::assertSame('Access denied', $result->message);
    }

    #[Test]
    public function handle_catches_unexpected_exception_as_internal(): void
    {
        $config = new GrpcConfig();

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
        ]);
        $handler->method('invoke')->willThrowException(
            new RuntimeException('Unexpected error'),
        );

        $registry = new ServiceRegistry();
        $registry->register($handler);

        $adapter = $this->createStub(GrpcTransportAdapterInterface::class);
        $pipeline = new InterceptorPipeline();

        $server = new GrpcServer($config, $registry, $adapter, $pipeline);

        $result = $server->handle('/helloworld.Greeter/SayHello', 'payload');

        self::assertSame(GrpcStatus::Internal, $result->status);
        self::assertSame('Internal server error', $result->message);
    }

    private function createRegistryWithGreeter(string $response = ''): ServiceRegistryInterface
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
        ]);
        $handler->method('invoke')->willReturn($response);

        $registry = new ServiceRegistry();
        $registry->register($handler);

        return $registry;
    }
}
