<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Tests\Unit\Server;

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
use Pulsar\Extension\Grpc\Interceptor\InterceptorInterface;
use Pulsar\Extension\Grpc\Interceptor\InterceptorPipeline;
use Pulsar\Extension\Grpc\Interceptor\InterceptorResult;
use Pulsar\Extension\Grpc\Server\RequestDispatcher;
use RuntimeException;
use stdClass;

#[CoversClass(RequestDispatcher::class)]
final class RequestDispatcherTest extends TestCase
{
    private function createContext(): CallContext
    {
        $methodName = 'SayHello';

        return new CallContext(
            method: new MethodDescriptor(
                name: $methodName,
                fullName: '/test.Service/' . $methodName,
                type: MethodType::Unary,
                inputType: 'test.Request',
                outputType: 'test.Response',
                handler: 'TestService::' . $methodName,
            ),
            payload: 'request',
        );
    }

    #[Test]
    public function dispatch_invokes_handler_and_returns_ok(): void
    {
        $pipeline = new InterceptorPipeline();
        $dispatcher = new RequestDispatcher($pipeline);

        $handler = $this->createStub(ServiceHandlerInterface::class);
        $handler->method('invoke')->willReturn('response-bytes');

        $context = $this->createContext();
        $result = $dispatcher->dispatch($context, $handler);

        self::assertTrue($result->isOk());
        self::assertSame('response-bytes', $result->payload);
    }

    #[Test]
    public function dispatch_catches_grpc_exception_from_handler(): void
    {
        $pipeline = new InterceptorPipeline();
        $dispatcher = new RequestDispatcher($pipeline);

        $handler = $this->createStub(ServiceHandlerInterface::class);
        $handler->method('invoke')->willThrowException(
            new GrpcException(GrpcStatus::NotFound, 'Resource not found'),
        );

        $context = $this->createContext();
        $result = $dispatcher->dispatch($context, $handler);

        self::assertFalse($result->isOk());
        self::assertSame(GrpcStatus::NotFound, $result->status);
        self::assertSame('Resource not found', $result->message);
    }

    #[Test]
    public function dispatch_catches_unexpected_exception_as_internal(): void
    {
        $pipeline = new InterceptorPipeline();
        $dispatcher = new RequestDispatcher($pipeline);

        $handler = $this->createStub(ServiceHandlerInterface::class);
        $handler->method('invoke')->willThrowException(
            new RuntimeException('Database connection lost'),
        );

        $context = $this->createContext();
        $result = $dispatcher->dispatch($context, $handler);

        self::assertFalse($result->isOk());
        self::assertSame(GrpcStatus::Internal, $result->status);
        self::assertSame('Internal server error', $result->message);
    }

    #[Test]
    public function dispatch_runs_interceptor_pipeline(): void
    {
        $interceptor = new class implements InterceptorInterface {
            public function handle(CallContext $context, Closure $next): InterceptorResult
            {
                $newContext = $context->withAttribute('intercepted', true);

                return $next($newContext);
            }
        };

        $pipeline = new InterceptorPipeline([$interceptor]);
        $dispatcher = new RequestDispatcher($pipeline);

        $handler = $this->createStub(ServiceHandlerInterface::class);
        $handler->method('invoke')->willReturn('ok');

        $context = $this->createContext();
        $result = $dispatcher->dispatch($context, $handler);

        self::assertTrue($result->isOk());
        self::assertSame('ok', $result->payload);
    }

    #[Test]
    public function dispatch_interceptor_can_short_circuit(): void
    {
        $interceptor = new class implements InterceptorInterface {
            public function handle(CallContext $context, Closure $next): InterceptorResult
            {
                return InterceptorResult::error(GrpcStatus::Unauthenticated, 'No token');
            }
        };

        $pipeline = new InterceptorPipeline([$interceptor]);
        $dispatcher = new RequestDispatcher($pipeline);

        $handler = $this->createStub(ServiceHandlerInterface::class);
        // invoke should never be called
        $handler->method('invoke')->willReturn('should-not-reach');

        $context = $this->createContext();
        $result = $dispatcher->dispatch($context, $handler);

        self::assertFalse($result->isOk());
        self::assertSame(GrpcStatus::Unauthenticated, $result->status);
        self::assertSame('No token', $result->message);
    }

    #[Test]
    public function dispatch_catches_grpc_exception_thrown_in_interceptor(): void
    {
        $interceptor = new class implements InterceptorInterface {
            public function handle(CallContext $context, Closure $next): InterceptorResult
            {
                throw GrpcException::permissionDenied('Forbidden by policy');
            }
        };

        $pipeline = new InterceptorPipeline([$interceptor]);
        $dispatcher = new RequestDispatcher($pipeline);

        $handler = $this->createStub(ServiceHandlerInterface::class);

        $context = $this->createContext();
        $result = $dispatcher->dispatch($context, $handler);

        self::assertFalse($result->isOk());
        self::assertSame(GrpcStatus::PermissionDenied, $result->status);
        self::assertSame('Forbidden by policy', $result->message);
    }

    #[Test]
    public function dispatch_multiple_interceptors_execute_in_order(): void
    {
        $tracker = new stdClass();
        $tracker->order = [];

        $first = $this->createOrderTrackingInterceptor($tracker, 'first');
        $second = $this->createOrderTrackingInterceptor($tracker, 'second');

        $pipeline = new InterceptorPipeline([$first, $second]);
        $dispatcher = new RequestDispatcher($pipeline);

        $handler = $this->createStub(ServiceHandlerInterface::class);
        $handler->method('invoke')->willReturn('done');

        $context = $this->createContext();
        $result = $dispatcher->dispatch($context, $handler);

        self::assertTrue($result->isOk());
        self::assertSame(['first', 'second'], $tracker->order);
    }

    #[Test]
    public function dispatch_with_empty_pipeline(): void
    {
        $pipeline = new InterceptorPipeline();
        $dispatcher = new RequestDispatcher($pipeline);

        $handler = $this->createStub(ServiceHandlerInterface::class);
        $handler->method('invoke')->willReturn('direct-response');

        $context = $this->createContext();
        $result = $dispatcher->dispatch($context, $handler);

        self::assertTrue($result->isOk());
        self::assertSame('direct-response', $result->payload);
    }

    private function createOrderTrackingInterceptor(stdClass $tracker, string $name): InterceptorInterface
    {
        return new class ($tracker, $name) implements InterceptorInterface {
            public function __construct(
                private readonly stdClass $tracker,
                private readonly string $name,
            ) {}

            public function handle(CallContext $context, Closure $next): InterceptorResult
            {
                /** @var list<string> $order */
                $order = $this->tracker->order;
                $order[] = $this->name;
                $this->tracker->order = $order;

                return $next($context);
            }
        };
    }
}
