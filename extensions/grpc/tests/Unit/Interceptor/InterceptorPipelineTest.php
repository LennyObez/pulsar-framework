<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Tests\Unit\Interceptor;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extension\Grpc\Config\GrpcConfig;
use Pulsar\Extension\Grpc\Config\InterceptorToggleConfig;
use Pulsar\Extension\Grpc\Error\GrpcStatus;
use Pulsar\Extension\Grpc\Handler\MethodDescriptor;
use Pulsar\Extension\Grpc\Handler\MethodType;
use Pulsar\Extension\Grpc\Interceptor\AuthValidatorInterface;
use Pulsar\Extension\Grpc\Interceptor\CallContext;
use Pulsar\Extension\Grpc\Interceptor\InterceptorInterface;
use Pulsar\Extension\Grpc\Interceptor\InterceptorPipeline;
use Pulsar\Extension\Grpc\Interceptor\InterceptorResult;
use Pulsar\Extension\Grpc\Interceptor\ValidationRuleResolverInterface;
use Pulsar\Observability\Tracing\TraceContextParserInterface;
use RuntimeException;

#[CoversClass(InterceptorPipeline::class)]
final class InterceptorPipelineTest extends TestCase
{
    #[Test]
    public function processCallsHandlerWhenNoInterceptors(): void
    {
        $pipeline = new InterceptorPipeline([]);
        $context = $this->createContext();

        $result = $pipeline->process($context, static fn(CallContext $ctx): InterceptorResult => InterceptorResult::ok('response'));

        self::assertTrue($result->isOk());
        self::assertSame('response', $result->payload);
    }

    #[Test]
    public function processExecutesInterceptorsInOrder(): void
    {
        $order = [];

        $first = $this->createOrderTrackingInterceptor($order, 'first');
        $second = $this->createOrderTrackingInterceptor($order, 'second');
        $third = $this->createOrderTrackingInterceptor($order, 'third');

        $pipeline = new InterceptorPipeline([$first, $second, $third]);
        $context = $this->createContext();

        $pipeline->process($context, static fn(CallContext $ctx): InterceptorResult => InterceptorResult::ok('done'));

        self::assertSame(['first', 'second', 'third'], $order);
    }

    #[Test]
    public function interceptorCanShortCircuitPipeline(): void
    {
        $handlerCalled = false;
        $order = [];

        $first = $this->createOrderTrackingInterceptor($order, 'first');

        $blocker = new class implements InterceptorInterface {
            public function handle(CallContext $context, Closure $next): InterceptorResult
            {
                return InterceptorResult::error(GrpcStatus::Unauthenticated, 'blocked');
            }
        };

        $third = $this->createOrderTrackingInterceptor($order, 'third');

        $pipeline = new InterceptorPipeline([$first, $blocker, $third]);
        $context = $this->createContext();

        $result = $pipeline->process($context, static function (CallContext $ctx) use (&$handlerCalled): InterceptorResult {
            $handlerCalled = true;

            return InterceptorResult::ok('done');
        });

        self::assertSame(GrpcStatus::Unauthenticated, $result->status);
        self::assertSame('blocked', $result->message);
        self::assertSame(['first'], $order);
        self::assertFalse($handlerCalled);
    }

    #[Test]
    public function interceptorCanModifyContextForNextInChain(): void
    {
        $adder = new class implements InterceptorInterface {
            public function handle(CallContext $context, Closure $next): InterceptorResult
            {
                return $next($context->withAttribute('injected', 'value'));
            }
        };

        $capturedContext = null;
        $pipeline = new InterceptorPipeline([$adder]);
        $context = $this->createContext();

        $pipeline->process($context, static function (CallContext $ctx) use (&$capturedContext): InterceptorResult {
            $capturedContext = $ctx;

            return InterceptorResult::ok('done');
        });

        self::assertNotNull($capturedContext);
        self::assertSame('value', $capturedContext->attributes['injected']);
    }

    #[Test]
    public function fromConfigCreatesFullPipelineWithAllTogglesEnabled(): void
    {
        $authValidator = $this->createStub(AuthValidatorInterface::class);
        $ruleResolver = $this->createStub(ValidationRuleResolverInterface::class);
        $logger = $this->createStub(LoggerInterface::class);
        $traceParser = $this->createStub(TraceContextParserInterface::class);

        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturnCallback(
            static fn(string $id): bool => match ($id) {
                TraceContextParserInterface::class,
                AuthValidatorInterface::class,
                ValidationRuleResolverInterface::class,
                LoggerInterface::class => true,
                default => false,
            },
        );
        $container->method('get')->willReturnCallback(
            static fn(string $id): object => match ($id) {
                TraceContextParserInterface::class => $traceParser,
                AuthValidatorInterface::class => $authValidator,
                ValidationRuleResolverInterface::class => $ruleResolver,
                LoggerInterface::class => $logger,
                default => throw new RuntimeException('Unexpected: ' . $id),
            },
        );

        $config = new GrpcConfig();
        $pipeline = InterceptorPipeline::fromConfig($config, $container);

        // All 5 interceptors should be present
        self::assertCount(5, $pipeline->interceptors());
        self::assertFalse($pipeline->isEmpty());
    }

    #[Test]
    public function fromConfigRespectsDisabledToggles(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturn(false);

        $config = new GrpcConfig(
            interceptors: new InterceptorToggleConfig(
                tracing: false,
                auth: false,
                rateLimit: false,
                validation: false,
                logging: false,
            ),
        );

        $pipeline = InterceptorPipeline::fromConfig($config, $container);

        self::assertTrue($pipeline->isEmpty());
        self::assertSame([], $pipeline->interceptors());
    }

    #[Test]
    public function fromConfigSkipsAuthWhenValidatorMissing(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturn(false);

        $config = new GrpcConfig(
            interceptors: new InterceptorToggleConfig(
                tracing: false,
                auth: true,
                rateLimit: false,
                validation: false,
                logging: false,
            ),
        );

        $pipeline = InterceptorPipeline::fromConfig($config, $container);

        // Auth requires AuthValidatorInterface — skipped when not in container
        self::assertTrue($pipeline->isEmpty());
    }

    #[Test]
    public function fromConfigSkipsValidationWhenResolverMissing(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturn(false);

        $config = new GrpcConfig(
            interceptors: new InterceptorToggleConfig(
                tracing: false,
                auth: false,
                rateLimit: false,
                validation: true,
                logging: false,
            ),
        );

        $pipeline = InterceptorPipeline::fromConfig($config, $container);

        self::assertTrue($pipeline->isEmpty());
    }

    #[Test]
    public function isEmptyReturnsTrueForEmptyPipeline(): void
    {
        $pipeline = new InterceptorPipeline([]);

        self::assertTrue($pipeline->isEmpty());
    }

    #[Test]
    public function interceptorsReturnsRegisteredInterceptors(): void
    {
        $interceptor = $this->createStub(InterceptorInterface::class);
        $pipeline = new InterceptorPipeline([$interceptor]);

        self::assertCount(1, $pipeline->interceptors());
        self::assertSame($interceptor, $pipeline->interceptors()[0]);
    }

    private function createContext(): CallContext
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
        );
    }

    /**
     * @param list<string> $order
     */
    private function createOrderTrackingInterceptor(array &$order, string $name): InterceptorInterface
    {
        return new class ($order, $name) implements InterceptorInterface {
            /** @param list<string> $order */
            public function __construct(
                private array &$order,
                private readonly string $name,
            ) {}

            public function handle(CallContext $context, Closure $next): InterceptorResult
            {
                $this->order[] = $this->name;

                return $next($context);
            }
        };
    }
}
