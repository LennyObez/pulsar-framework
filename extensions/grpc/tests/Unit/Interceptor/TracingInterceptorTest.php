<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Tests\Unit\Interceptor;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Grpc\Error\GrpcStatus;
use Pulsar\Extension\Grpc\Handler\MethodDescriptor;
use Pulsar\Extension\Grpc\Handler\MethodType;
use Pulsar\Extension\Grpc\Interceptor\CallContext;
use Pulsar\Extension\Grpc\Interceptor\InterceptorResult;
use Pulsar\Extension\Grpc\Interceptor\TracingInterceptor;
use Pulsar\Observability\Tracing\Span;
use Pulsar\Observability\Tracing\SpanId;
use Pulsar\Observability\Tracing\SpanStatus;
use Pulsar\Observability\Tracing\TraceContext;
use Pulsar\Observability\Tracing\TraceContextParserInterface;
use Pulsar\Observability\Tracing\TraceId;
use RuntimeException;

#[CoversClass(TracingInterceptor::class)]
final class TracingInterceptorTest extends TestCase
{
    #[Test]
    public function createsSpanAndPassesItDownstream(): void
    {
        $interceptor = new TracingInterceptor($this->createParserStub());
        $context = $this->createContext();

        $capturedSpan = null;
        $result = $interceptor->handle($context, static function (CallContext $ctx) use (&$capturedSpan): InterceptorResult {
            $capturedSpan = $ctx->attributes['tracing.span'] ?? null;

            return InterceptorResult::ok('response');
        });

        self::assertTrue($result->isOk());
        self::assertInstanceOf(Span::class, $capturedSpan);
        self::assertSame('grpc.call', $capturedSpan->name);
    }

    #[Test]
    public function setsRpcAttributes(): void
    {
        $interceptor = new TracingInterceptor($this->createParserStub());
        $context = $this->createContext();

        $capturedSpan = null;
        $interceptor->handle($context, static function (CallContext $ctx) use (&$capturedSpan): InterceptorResult {
            $capturedSpan = $ctx->attributes['tracing.span'];

            return InterceptorResult::ok('');
        });

        self::assertInstanceOf(Span::class, $capturedSpan);
        $attrs = $capturedSpan->attributes();
        self::assertSame('grpc', $attrs['rpc.system']);
        self::assertSame('SayHello', $attrs['rpc.method']);
        self::assertSame('helloworld.Greeter', $attrs['rpc.service']);
    }

    #[Test]
    public function parsesTraceparentFromMetadata(): void
    {
        $parser = $this->createStub(TraceContextParserInterface::class);
        $parser->method('parse')->willReturn(
            new TraceContext(
                new TraceId('4bf92f3577b34da6a3ce929d0e0e4736'),
                new SpanId('00f067aa0ba902b7'),
            ),
        );

        $interceptor = new TracingInterceptor($parser);
        $context = $this->createContext([
            'traceparent' => ['00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01'],
        ]);

        $capturedSpan = null;
        $interceptor->handle($context, static function (CallContext $ctx) use (&$capturedSpan): InterceptorResult {
            $capturedSpan = $ctx->attributes['tracing.span'];

            return InterceptorResult::ok('');
        });

        self::assertInstanceOf(Span::class, $capturedSpan);
        // Parent span ID should be the incoming span ID
        self::assertNotNull($capturedSpan->parentSpanId);
        self::assertSame('00f067aa0ba902b7', $capturedSpan->parentSpanId->value);
        // Trace ID should be preserved
        self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $capturedSpan->context->traceId->value);
    }

    #[Test]
    public function createsNewTraceWhenTraceparentMissing(): void
    {
        $interceptor = new TracingInterceptor($this->createParserStub());
        $context = $this->createContext();

        $capturedSpan = null;
        $interceptor->handle($context, static function (CallContext $ctx) use (&$capturedSpan): InterceptorResult {
            $capturedSpan = $ctx->attributes['tracing.span'];

            return InterceptorResult::ok('');
        });

        self::assertInstanceOf(Span::class, $capturedSpan);
        self::assertNotNull($capturedSpan->parentSpanId);
        // A new root context was created, so there is a parent (the root)
        self::assertNotEmpty($capturedSpan->context->traceId->value);
    }

    #[Test]
    public function createsNewTraceWhenTraceparentInvalid(): void
    {
        $interceptor = new TracingInterceptor($this->createParserStub());
        $context = $this->createContext([
            'traceparent' => ['invalid-header-value'],
        ]);

        $capturedSpan = null;
        $interceptor->handle($context, static function (CallContext $ctx) use (&$capturedSpan): InterceptorResult {
            $capturedSpan = $ctx->attributes['tracing.span'];

            return InterceptorResult::ok('');
        });

        self::assertInstanceOf(Span::class, $capturedSpan);
        self::assertNotEmpty($capturedSpan->context->traceId->value);
    }

    #[Test]
    public function setsOkStatusOnSuccess(): void
    {
        $interceptor = new TracingInterceptor($this->createParserStub());
        $context = $this->createContext();

        $capturedSpan = null;
        $interceptor->handle($context, static function (CallContext $ctx) use (&$capturedSpan): InterceptorResult {
            $capturedSpan = $ctx->attributes['tracing.span'];

            return InterceptorResult::ok('response');
        });

        self::assertInstanceOf(Span::class, $capturedSpan);
        self::assertSame(SpanStatus::Ok, $capturedSpan->status);
        self::assertTrue($capturedSpan->hasEnded());
    }

    #[Test]
    public function setsErrorStatusOnFailure(): void
    {
        $interceptor = new TracingInterceptor($this->createParserStub());
        $context = $this->createContext();

        $capturedSpan = null;
        $interceptor->handle($context, static function (CallContext $ctx) use (&$capturedSpan): InterceptorResult {
            $capturedSpan = $ctx->attributes['tracing.span'];

            return InterceptorResult::error(GrpcStatus::Internal, 'server error');
        });

        self::assertInstanceOf(Span::class, $capturedSpan);
        self::assertSame(SpanStatus::Error, $capturedSpan->status);
        self::assertTrue($capturedSpan->hasEnded());
    }

    #[Test]
    public function setsGrpcStatusCodeAttribute(): void
    {
        $interceptor = new TracingInterceptor($this->createParserStub());
        $context = $this->createContext();

        $capturedSpan = null;
        $interceptor->handle($context, static function (CallContext $ctx) use (&$capturedSpan): InterceptorResult {
            $capturedSpan = $ctx->attributes['tracing.span'];

            return InterceptorResult::error(GrpcStatus::NotFound, 'not found');
        });

        self::assertInstanceOf(Span::class, $capturedSpan);
        self::assertSame(GrpcStatus::NotFound->value, $capturedSpan->attributes()['rpc.grpc.status_code']);
    }

    #[Test]
    public function endsSpanEvenWhenHandlerThrows(): void
    {
        $interceptor = new TracingInterceptor($this->createParserStub());
        $context = $this->createContext();

        $capturedSpan = null;

        try {
            $interceptor->handle($context, static function (CallContext $ctx) use (&$capturedSpan): InterceptorResult {
                $capturedSpan = $ctx->attributes['tracing.span'];

                throw new RuntimeException('handler failure');
            });
        } catch (RuntimeException) {
            // Expected
        }

        self::assertInstanceOf(Span::class, $capturedSpan);
        self::assertTrue($capturedSpan->hasEnded());
    }

    /**
     * @param array<string, list<string>> $metadata
     */
    private function createContext(array $metadata = []): CallContext
    {
        return new CallContext(
            method: new MethodDescriptor(
                name: 'SayHello',
                fullName: '/helloworld.Greeter/SayHello',
                type: MethodType::Unary,
                inputType: 'helloworld.HelloRequest',
                outputType: 'helloworld.HelloReply',
                handler: 'App\\Grpc\\GreeterHandler::sayHello',
            ),
            payload: '{}',
            metadata: $metadata,
        );
    }

    private function createParserStub(): TraceContextParserInterface
    {
        $stub = $this->createStub(TraceContextParserInterface::class);
        $stub->method('parse')->willReturn(null);

        return $stub;
    }
}
