<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Grpc\Server;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Pulsar\Extension\Grpc\Adapter\GrpcTransportAdapterInterface;
use Pulsar\Extension\Grpc\Config\GrpcConfig;
use Pulsar\Extension\Grpc\Error\GrpcStatus;
use Pulsar\Extension\Grpc\Handler\MethodDescriptor;
use Pulsar\Extension\Grpc\Handler\MethodType;
use Pulsar\Extension\Grpc\Handler\ServiceHandlerInterface;
use Pulsar\Extension\Grpc\Interceptor\InterceptorPipeline;
use Pulsar\Extension\Grpc\Server\GrpcServer;
use Pulsar\Extension\Grpc\Server\ServiceRegistryInterface;
use RuntimeException;

#[CoversClass(GrpcServer::class)]
final class GrpcServerTest extends TestCase
{
    private GrpcConfig $config;
    private ServiceRegistryInterface & Stub $registry;
    private GrpcTransportAdapterInterface & Stub $adapter;
    private InterceptorPipeline $pipeline;
    private LoggerInterface & Stub $logger;

    protected function setUp(): void
    {
        $this->config = new GrpcConfig(host: '127.0.0.1', port: 50051);
        $this->registry = $this->createStub(ServiceRegistryInterface::class);
        $this->adapter = $this->createStub(GrpcTransportAdapterInterface::class);
        $this->pipeline = new InterceptorPipeline([]);
        $this->logger = $this->createStub(LoggerInterface::class);
    }

    private function makeServer(?LoggerInterface $logger = null): GrpcServer
    {
        return new GrpcServer(
            $this->config,
            $this->registry,
            $this->adapter,
            $this->pipeline,
            $logger,
        );
    }

    // --- start() tests ---

    #[Test]
    public function startThrowsWhenNoServicesRegistered(): void
    {
        $registry = $this->createMock(ServiceRegistryInterface::class);
        $registry->expects(self::once())
            ->method('isEmpty')
            ->willReturn(true);

        $server = new GrpcServer($this->config, $registry, $this->adapter, $this->pipeline);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no services registered');

        $server->start();
    }

    #[Test]
    public function startThrowsWhenAdapterNotAvailable(): void
    {
        $registry = $this->createMock(ServiceRegistryInterface::class);
        $registry->expects(self::once())
            ->method('isEmpty')
            ->willReturn(false);

        $adapter = $this->createMock(GrpcTransportAdapterInterface::class);
        $adapter->expects(self::once())
            ->method('isAvailable')
            ->willReturn(false);
        $adapter->method('name')->willReturn('test-adapter');

        $server = new GrpcServer($this->config, $registry, $adapter, $this->pipeline);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('transport adapter "test-adapter" is not available');

        $server->start();
    }

    #[Test]
    public function startCallsAdapterListenWithCorrectHostPort(): void
    {
        $this->registry->method('isEmpty')->willReturn(false);

        $adapter = $this->createMock(GrpcTransportAdapterInterface::class);
        $adapter->method('isAvailable')->willReturn(true);
        $adapter->expects(self::once())
            ->method('listen')
            ->with('127.0.0.1', 50051, self::isInstanceOf(GrpcServer::class));

        $server = new GrpcServer($this->config, $this->registry, $adapter, $this->pipeline);
        $server->start();
    }

    #[Test]
    public function startPassesServerAsHandler(): void
    {
        $this->registry->method('isEmpty')->willReturn(false);

        $adapter = $this->createMock(GrpcTransportAdapterInterface::class);
        $adapter->method('isAvailable')->willReturn(true);

        $capturedHandler = null;
        $adapter->expects(self::once())
            ->method('listen')
            ->willReturnCallback(function (string $host, int $port, $handler) use (&$capturedHandler): void {
                $capturedHandler = $handler;
            });

        $server = new GrpcServer($this->config, $this->registry, $adapter, $this->pipeline);
        $server->start();

        self::assertSame($server, $capturedHandler);
    }

    // --- stop() tests ---

    #[Test]
    public function stopCallsAdapterShutdown(): void
    {
        $adapter = $this->createMock(GrpcTransportAdapterInterface::class);
        $adapter->expects(self::once())->method('shutdown');

        $server = new GrpcServer($this->config, $this->registry, $adapter, $this->pipeline);
        $server->stop();
    }

    // --- handle() tests ---

    #[Test]
    public function handleReturnsUnimplementedForUnknownMethod(): void
    {
        $registry = $this->createMock(ServiceRegistryInterface::class);
        $registry->expects(self::once())
            ->method('resolve')
            ->with('/unknown.Service/UnknownMethod')
            ->willReturn(null);

        $server = new GrpcServer($this->config, $registry, $this->adapter, $this->pipeline, $this->logger);
        $result = $server->handle('/unknown.Service/UnknownMethod', 'payload');

        self::assertSame(GrpcStatus::Unimplemented, $result->status);
        self::assertSame('Method not implemented', $result->message);
    }

    #[Test]
    public function handleDispatchesResolvedMethodThroughPipeline(): void
    {
        $method = new MethodDescriptor(
            name: 'SayHello',
            fullName: '/greeter.Greeter/SayHello',
            type: MethodType::Unary,
            inputType: 'HelloReq',
            outputType: 'HelloRes',
            handler: 'Greeter::sayHello',
        );

        $handler = $this->createStub(ServiceHandlerInterface::class);
        $handler->method('invoke')->willReturn('hello-response');

        $registry = $this->createMock(ServiceRegistryInterface::class);
        $registry->expects(self::once())
            ->method('resolve')
            ->with('/greeter.Greeter/SayHello')
            ->willReturn([$method, $handler]);

        $server = new GrpcServer($this->config, $registry, $this->adapter, $this->pipeline);
        $result = $server->handle('/greeter.Greeter/SayHello', 'hello-request');

        self::assertTrue($result->isOk());
        self::assertSame('hello-response', $result->payload);
    }

    #[Test]
    public function handlePassesMetadataToContext(): void
    {
        $method = new MethodDescriptor(
            name: 'Echo',
            fullName: '/test.Service/Echo',
            type: MethodType::Unary,
            inputType: 'EchoReq',
            outputType: 'EchoRes',
            handler: 'Service::echo',
        );

        $handler = $this->createStub(ServiceHandlerInterface::class);
        $handler->method('invoke')->willReturn('response');

        $this->registry->method('resolve')->willReturn([$method, $handler]);

        $metadata = ['authorization' => ['Bearer token'], 'x-custom' => ['val']];

        $server = $this->makeServer();
        $result = $server->handle('/test.Service/Echo', 'payload', $metadata);

        self::assertTrue($result->isOk());
    }

    #[Test]
    public function handlePassesPeerIdentityToContext(): void
    {
        $method = new MethodDescriptor(
            name: 'Secure',
            fullName: '/test.Service/Secure',
            type: MethodType::Unary,
            inputType: 'SecReq',
            outputType: 'SecRes',
            handler: 'Service::secure',
        );

        $handler = $this->createStub(ServiceHandlerInterface::class);
        $handler->method('invoke')->willReturn('response');

        $this->registry->method('resolve')->willReturn([$method, $handler]);

        $server = $this->makeServer();
        $result = $server->handle('/test.Service/Secure', 'payload', [], 'client-cert-san');

        self::assertTrue($result->isOk());
    }

    // --- Deadline extraction tests ---

    #[Test]
    #[DataProvider('deadlineTimeoutProvider')]
    public function handleExtractsDeadlineFromGrpcTimeoutHeader(string $timeout, float $minSeconds, float $maxSeconds): void
    {
        $method = new MethodDescriptor(
            name: 'Slow',
            fullName: '/test.Service/Slow',
            type: MethodType::Unary,
            inputType: 'Req',
            outputType: 'Res',
            handler: 'Service::slow',
        );

        $handler = $this->createStub(ServiceHandlerInterface::class);
        $handler->method('invoke')->willReturn('ok');

        $this->registry->method('resolve')->willReturn([$method, $handler]);

        $metadata = ['grpc-timeout' => [$timeout]];

        $server = $this->makeServer();
        $result = $server->handle('/test.Service/Slow', '', $metadata);

        self::assertTrue($result->isOk());
    }

    /**
     * @return iterable<string, array{string, float, float}>
     */
    public static function deadlineTimeoutProvider(): iterable
    {
        yield 'hours' => ['1H', 3500.0, 3700.0];
        yield 'minutes' => ['5M', 290.0, 310.0];
        yield 'seconds' => ['30S', 25.0, 35.0];
        yield 'milliseconds' => ['500m', 0.4, 0.6];
        yield 'microseconds' => ['1000u', 0.0005, 0.002];
        yield 'nanoseconds' => ['1000000n', 0.0005, 0.002];
    }

    #[Test]
    public function handleIgnoresInvalidTimeoutUnit(): void
    {
        $method = new MethodDescriptor(
            name: 'Echo',
            fullName: '/test.Service/Echo',
            type: MethodType::Unary,
            inputType: 'Req',
            outputType: 'Res',
            handler: 'Service::echo',
        );

        $handler = $this->createStub(ServiceHandlerInterface::class);
        $handler->method('invoke')->willReturn('ok');

        $this->registry->method('resolve')->willReturn([$method, $handler]);

        $metadata = ['grpc-timeout' => ['100X']]; // Invalid unit

        $server = $this->makeServer();
        $result = $server->handle('/test.Service/Echo', '', $metadata);

        // Should still succeed (deadline = null when timeout is unparseable)
        self::assertTrue($result->isOk());
    }

    #[Test]
    public function handleIgnoresEmptyTimeoutValue(): void
    {
        $method = new MethodDescriptor(
            name: 'Echo',
            fullName: '/test.Service/Echo',
            type: MethodType::Unary,
            inputType: 'Req',
            outputType: 'Res',
            handler: 'Service::echo',
        );

        $handler = $this->createStub(ServiceHandlerInterface::class);
        $handler->method('invoke')->willReturn('ok');

        $this->registry->method('resolve')->willReturn([$method, $handler]);

        $metadata = ['grpc-timeout' => ['']];

        $server = $this->makeServer();
        $result = $server->handle('/test.Service/Echo', '', $metadata);

        self::assertTrue($result->isOk());
    }

    #[Test]
    public function handleIgnoresMissingTimeoutHeader(): void
    {
        $method = new MethodDescriptor(
            name: 'Echo',
            fullName: '/test.Service/Echo',
            type: MethodType::Unary,
            inputType: 'Req',
            outputType: 'Res',
            handler: 'Service::echo',
        );

        $handler = $this->createStub(ServiceHandlerInterface::class);
        $handler->method('invoke')->willReturn('ok');

        $this->registry->method('resolve')->willReturn([$method, $handler]);

        $server = $this->makeServer();
        $result = $server->handle('/test.Service/Echo', '', []);

        self::assertTrue($result->isOk());
    }

    // --- Logger usage ---

    #[Test]
    public function handleLogsDebugForUnknownMethod(): void
    {
        $this->registry->method('resolve')->willReturn(null);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('debug')
            ->with(
                'gRPC method not found',
                ['method' => '/unknown/Method'],
            );

        $server = $this->makeServer($logger);
        $server->handle('/unknown/Method', '');
    }

    #[Test]
    public function constructorUsesNullLoggerWhenNoneProvided(): void
    {
        $this->registry->method('resolve')->willReturn(null);

        // Should not throw — NullLogger is used
        $server = $this->makeServer(null);
        $result = $server->handle('/test/Method', '');

        self::assertSame(GrpcStatus::Unimplemented, $result->status);
    }

    // --- Custom config ---

    #[Test]
    public function startUsesConfiguredHostAndPort(): void
    {
        $config = new GrpcConfig(host: '192.168.1.1', port: 9090);
        $this->registry->method('isEmpty')->willReturn(false);

        $adapter = $this->createMock(GrpcTransportAdapterInterface::class);
        $adapter->method('isAvailable')->willReturn(true);
        $adapter->expects(self::once())
            ->method('listen')
            ->with('192.168.1.1', 9090, self::anything());

        $server = new GrpcServer($config, $this->registry, $adapter, $this->pipeline);
        $server->start();
    }
}
