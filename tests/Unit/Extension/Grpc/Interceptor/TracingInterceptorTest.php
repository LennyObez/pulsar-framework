<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Grpc\Interceptor;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Grpc\Error\GrpcStatus;
use Pulsar\Extension\Grpc\Handler\MethodDescriptor;
use Pulsar\Extension\Grpc\Handler\MethodType;
use Pulsar\Extension\Grpc\Interceptor\CallContext;
use Pulsar\Extension\Grpc\Interceptor\InterceptorResult;
use Pulsar\Extension\Grpc\Interceptor\TracingInterceptor;
use Pulsar\Observability\Tracing\Span;
use Pulsar\Observability\Tracing\TraceContext;
use Pulsar\Observability\Tracing\TraceContextParserInterface;

#[CoversClass(TracingInterceptor::class)]
final class TracingInterceptorTest extends TestCase
{
    /** @param array<string, list<string>> $metadata */
    private function makeContext(array $metadata = []): CallContext
    {
        return new CallContext(
            method: new MethodDescriptor(
                name: 'GetUser',
                fullName: '/users.UserService/GetUser',
                type: MethodType::Unary,
                inputType: 'GetUserReq',
                outputType: 'GetUserRes',
                handler: 'UserService::getUser',
            ),
            payload: '',
            metadata: $metadata,
        );
    }

    #[Test]
    public function createsSpanFromTraceparentHeader(): void
    {
        $traceCtx = TraceContext::create();

        /** @var TraceContextParserInterface&Stub $parser */
        $parser = $this->createStub(TraceContextParserInterface::class);
        $parser->method('parse')->willReturn($traceCtx);

        $interceptor = new TracingInterceptor($parser);

        $capturedCtx = null;
        $result = $interceptor->handle(
            $this->makeContext(['traceparent' => ['00-abcd-ef01-01']]),
            static function (CallContext $ctx) use (&$capturedCtx): InterceptorResult {
                $capturedCtx = $ctx;
                return InterceptorResult::ok('ok');
            },
        );

        self::assertTrue($result->isOk());
        self::assertInstanceOf(CallContext::class, $capturedCtx);
        self::assertArrayHasKey('tracing.span', $capturedCtx->attributes);
    }

    #[Test]
    public function createsNewTraceContextWhenNoHeader(): void
    {
        /** @var TraceContextParserInterface&Stub $parser */
        $parser = $this->createStub(TraceContextParserInterface::class);
        $parser->method('parse')->willReturn(null);

        $interceptor = new TracingInterceptor($parser);

        $capturedCtx = null;
        $result = $interceptor->handle(
            $this->makeContext(),
            static function (CallContext $ctx) use (&$capturedCtx): InterceptorResult {
                $capturedCtx = $ctx;
                return InterceptorResult::ok('ok');
            },
        );

        self::assertTrue($result->isOk());
        self::assertInstanceOf(CallContext::class, $capturedCtx);
        self::assertArrayHasKey('tracing.span', $capturedCtx->attributes);
    }

    #[Test]
    public function setsErrorStatusOnFailedResult(): void
    {
        /** @var TraceContextParserInterface&Stub $parser */
        $parser = $this->createStub(TraceContextParserInterface::class);
        $parser->method('parse')->willReturn(null);

        $interceptor = new TracingInterceptor($parser);

        $result = $interceptor->handle(
            $this->makeContext(),
            static fn(CallContext $ctx): InterceptorResult => InterceptorResult::error(
                GrpcStatus::Internal,
                'oops',
            ),
        );

        self::assertFalse($result->isOk());
        self::assertSame(GrpcStatus::Internal, $result->status);
    }

    #[Test]
    public function extractsServiceNameFromFullMethodName(): void
    {
        /** @var TraceContextParserInterface&Stub $parser */
        $parser = $this->createStub(TraceContextParserInterface::class);

        $interceptor = new TracingInterceptor($parser);

        $capturedCtx = null;
        $interceptor->handle(
            $this->makeContext(),
            static function (CallContext $ctx) use (&$capturedCtx): InterceptorResult {
                $capturedCtx = $ctx;
                return InterceptorResult::ok('ok');
            },
        );

        self::assertInstanceOf(CallContext::class, $capturedCtx);
        $span = $capturedCtx->attributes['tracing.span'];
        self::assertInstanceOf(Span::class, $span);
        self::assertSame('grpc.call', $span->name);
    }
}
