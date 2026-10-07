<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Grpc\Interceptor;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Grpc\Error\GrpcStatus;
use Pulsar\Extension\Grpc\Handler\MethodDescriptor;
use Pulsar\Extension\Grpc\Handler\MethodType;
use Pulsar\Extension\Grpc\Interceptor\CallContext;
use Pulsar\Extension\Grpc\Interceptor\InterceptorInterface;
use Pulsar\Extension\Grpc\Interceptor\InterceptorPipeline;
use Pulsar\Extension\Grpc\Interceptor\InterceptorResult;

#[CoversClass(InterceptorPipeline::class)]
final class InterceptorPipelineTest extends TestCase
{
    private function makeContext(): CallContext
    {
        return new CallContext(
            method: new MethodDescriptor(
                name: 'SayHello',
                fullName: '/helloworld.Greeter/SayHello',
                type: MethodType::Unary,
                inputType: 'HelloRequest',
                outputType: 'HelloReply',
                handler: 'App\\Greeter::sayHello',
            ),
            payload: '{"name":"world"}',
        );
    }

    #[Test]
    public function emptyPipelineCallsHandler(): void
    {
        $pipeline = new InterceptorPipeline([]);

        $result = $pipeline->process(
            $this->makeContext(),
            static fn(CallContext $ctx): InterceptorResult => InterceptorResult::ok('response'),
        );

        self::assertTrue($result->isOk());
        self::assertSame('response', $result->payload);
    }

    #[Test]
    public function isEmptyReturnsTrueForEmptyPipeline(): void
    {
        $pipeline = new InterceptorPipeline([]);

        self::assertTrue($pipeline->isEmpty());
    }

    #[Test]
    public function isEmptyReturnsFalseWithInterceptors(): void
    {
        $interceptor = new class implements InterceptorInterface {
            public function handle(CallContext $context, Closure $next): InterceptorResult
            {
                return $next($context);
            }
        };

        $pipeline = new InterceptorPipeline([$interceptor]);

        self::assertFalse($pipeline->isEmpty());
    }

    #[Test]
    public function interceptorsReturnsRegisteredInterceptors(): void
    {
        $interceptor = new class implements InterceptorInterface {
            public function handle(CallContext $context, Closure $next): InterceptorResult
            {
                return $next($context);
            }
        };

        $pipeline = new InterceptorPipeline([$interceptor]);

        self::assertCount(1, $pipeline->interceptors());
        self::assertSame($interceptor, $pipeline->interceptors()[0]);
    }

    #[Test]
    public function interceptorsExecuteInOrder(): void
    {
        $order = [];

        $first = new class (function (string $v) use (&$order): void {
            $order[] = $v;
        }) implements InterceptorInterface {
            /** @param Closure(string): void $record */
            public function __construct(private readonly Closure $record) {}

            public function handle(CallContext $context, Closure $next): InterceptorResult
            {
                ($this->record)('first');
                return $next($context);
            }
        };

        $second = new class (function (string $v) use (&$order): void {
            $order[] = $v;
        }) implements InterceptorInterface {
            /** @param Closure(string): void $record */
            public function __construct(private readonly Closure $record) {}

            public function handle(CallContext $context, Closure $next): InterceptorResult
            {
                ($this->record)('second');
                return $next($context);
            }
        };

        $pipeline = new InterceptorPipeline([$first, $second]);

        $pipeline->process(
            $this->makeContext(),
            static fn(CallContext $ctx): InterceptorResult => InterceptorResult::ok('done'),
        );

        self::assertSame(['first', 'second'], $order);
    }

    #[Test]
    public function interceptorCanShortCircuit(): void
    {
        $blocker = new class implements InterceptorInterface {
            public function handle(CallContext $context, Closure $next): InterceptorResult
            {
                return InterceptorResult::error(GrpcStatus::Unauthenticated, 'blocked');
            }
        };

        $shouldNotRun = new class implements InterceptorInterface {
            public bool $ran = false;

            public function handle(CallContext $context, Closure $next): InterceptorResult
            {
                $this->ran = true;
                return $next($context);
            }
        };

        $pipeline = new InterceptorPipeline([$blocker, $shouldNotRun]);

        $result = $pipeline->process(
            $this->makeContext(),
            static fn(CallContext $ctx): InterceptorResult => InterceptorResult::ok('ok'),
        );

        self::assertFalse($result->isOk());
        self::assertSame(GrpcStatus::Unauthenticated, $result->status);
        self::assertFalse($shouldNotRun->ran);
    }

    #[Test]
    public function interceptorCanModifyContext(): void
    {
        $enricher = new class implements InterceptorInterface {
            public function handle(CallContext $context, Closure $next): InterceptorResult
            {
                return $next($context->withAttribute('enriched', true));
            }
        };

        $capturedCtx = null;
        $pipeline = new InterceptorPipeline([$enricher]);

        $pipeline->process(
            $this->makeContext(),
            static function (CallContext $ctx) use (&$capturedCtx): InterceptorResult {
                $capturedCtx = $ctx;
                return InterceptorResult::ok('ok');
            },
        );

        self::assertInstanceOf(CallContext::class, $capturedCtx);
        self::assertTrue($capturedCtx->attributes['enriched']);
    }
}
