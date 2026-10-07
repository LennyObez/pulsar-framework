<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Grpc\Server;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Grpc\Error\GrpcException;
use Pulsar\Extension\Grpc\Error\GrpcStatus;
use Pulsar\Extension\Grpc\Handler\MethodDescriptor;
use Pulsar\Extension\Grpc\Handler\MethodType;
use Pulsar\Extension\Grpc\Handler\ServiceHandlerInterface;
use Pulsar\Extension\Grpc\Interceptor\CallContext;
use Pulsar\Extension\Grpc\Interceptor\InterceptorPipeline;
use Pulsar\Extension\Grpc\Server\RequestDispatcher;
use RuntimeException;

#[CoversClass(RequestDispatcher::class)]
final class RequestDispatcherTest extends TestCase
{
    private function makeContext(): CallContext
    {
        return new CallContext(
            method: new MethodDescriptor(
                name: 'Echo',
                fullName: '/test.TestService/Echo',
                type: MethodType::Unary,
                inputType: 'EchoReq',
                outputType: 'EchoRes',
                handler: 'TestService::echo',
            ),
            payload: '{"msg":"hello"}',
        );
    }

    #[Test]
    public function dispatchesSuccessfullyThroughPipeline(): void
    {
        $handler = $this->createStub(ServiceHandlerInterface::class);
        $handler->method('invoke')->willReturn('response-payload');

        $dispatcher = new RequestDispatcher(new InterceptorPipeline([]));
        $result = $dispatcher->dispatch($this->makeContext(), $handler);

        self::assertTrue($result->isOk());
        self::assertSame('response-payload', $result->payload);
    }

    #[Test]
    public function catchesGrpcExceptionFromHandler(): void
    {
        $handler = $this->createStub(ServiceHandlerInterface::class);
        $handler->method('invoke')->willThrowException(
            new GrpcException(GrpcStatus::NotFound, 'Entity not found'),
        );

        $dispatcher = new RequestDispatcher(new InterceptorPipeline([]));
        $result = $dispatcher->dispatch($this->makeContext(), $handler);

        self::assertFalse($result->isOk());
        self::assertSame(GrpcStatus::NotFound, $result->status);
        self::assertSame('Entity not found', $result->message);
    }

    #[Test]
    public function catchesGenericExceptionWithInternalStatus(): void
    {
        $handler = $this->createStub(ServiceHandlerInterface::class);
        $handler->method('invoke')->willThrowException(
            new RuntimeException('Unexpected error'),
        );

        $dispatcher = new RequestDispatcher(new InterceptorPipeline([]));
        $result = $dispatcher->dispatch($this->makeContext(), $handler);

        self::assertFalse($result->isOk());
        self::assertSame(GrpcStatus::Internal, $result->status);
        self::assertSame('Internal server error', $result->message);
    }

    #[Test]
    public function catchesGrpcExceptionFromPipelineInterceptor(): void
    {
        $handler = $this->createStub(ServiceHandlerInterface::class);
        $handler->method('invoke')->willReturn('ok');

        $throwingInterceptor = new class implements \Pulsar\Extension\Grpc\Interceptor\InterceptorInterface {
            public function handle(CallContext $context, Closure $next): \Pulsar\Extension\Grpc\Interceptor\InterceptorResult
            {
                throw new GrpcException(GrpcStatus::Unavailable, 'Service temporarily unavailable');
            }
        };

        $dispatcher = new RequestDispatcher(new InterceptorPipeline([$throwingInterceptor]));
        $result = $dispatcher->dispatch($this->makeContext(), $handler);

        self::assertSame(GrpcStatus::Unavailable, $result->status);
    }
}
