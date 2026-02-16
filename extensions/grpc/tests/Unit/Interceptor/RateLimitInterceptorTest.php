<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Tests\Unit\Interceptor;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Grpc\Config\RateLimitConfig;
use Pulsar\Extension\Grpc\Error\GrpcStatus;
use Pulsar\Extension\Grpc\Handler\MethodDescriptor;
use Pulsar\Extension\Grpc\Handler\MethodType;
use Pulsar\Extension\Grpc\Interceptor\CallContext;
use Pulsar\Extension\Grpc\Interceptor\InterceptorResult;
use Pulsar\Extension\Grpc\Interceptor\RateLimitInterceptor;

#[CoversClass(RateLimitInterceptor::class)]
final class RateLimitInterceptorTest extends TestCase
{
    #[Test]
    public function allowsRequestWhenUnderLimit(): void
    {
        $config = new RateLimitConfig(maxRequestsPerSecond: 100, burstSize: 10);
        $interceptor = new RateLimitInterceptor($config);
        $context = $this->createContext();

        $result = $interceptor->handle(
            $context,
            static fn(CallContext $ctx): InterceptorResult => InterceptorResult::ok('allowed'),
        );

        self::assertTrue($result->isOk());
        self::assertSame('allowed', $result->payload);
    }

    #[Test]
    public function rejectsWhenBurstExhausted(): void
    {
        $config = new RateLimitConfig(maxRequestsPerSecond: 100, burstSize: 3);
        $interceptor = new RateLimitInterceptor($config, static fn(): float => 1000.0);
        $context = $this->createContext();

        $handler = static fn(CallContext $ctx): InterceptorResult => InterceptorResult::ok('ok');

        // Exhaust burst capacity
        for ($i = 0; $i < 3; $i++) {
            $result = $interceptor->handle($context, $handler);
            self::assertTrue($result->isOk(), "Request $i should succeed");
        }

        // Next request should be rejected
        $result = $interceptor->handle($context, $handler);
        self::assertSame(GrpcStatus::ResourceExhausted, $result->status);
        self::assertStringContainsString('Rate limit exceeded', $result->message);
    }

    #[Test]
    public function tracksLimitsPerMethod(): void
    {
        $config = new RateLimitConfig(maxRequestsPerSecond: 100, burstSize: 2);
        $interceptor = new RateLimitInterceptor($config, static fn(): float => 1000.0);

        $handler = static fn(CallContext $ctx): InterceptorResult => InterceptorResult::ok('ok');

        $contextA = $this->createContext('/service.A/MethodA');
        $contextB = $this->createContext('/service.B/MethodB');

        // Exhaust method A
        $interceptor->handle($contextA, $handler);
        $interceptor->handle($contextA, $handler);
        $resultA = $interceptor->handle($contextA, $handler);

        self::assertSame(GrpcStatus::ResourceExhausted, $resultA->status);

        // Method B should still work
        $resultB = $interceptor->handle($contextB, $handler);
        self::assertTrue($resultB->isOk());
    }

    #[Test]
    public function singleBurstAllowsOneRequest(): void
    {
        $config = new RateLimitConfig(maxRequestsPerSecond: 1, burstSize: 1);
        $interceptor = new RateLimitInterceptor($config, static fn(): float => 1000.0);
        $context = $this->createContext();

        $handler = static fn(CallContext $ctx): InterceptorResult => InterceptorResult::ok('ok');

        $result = $interceptor->handle($context, $handler);
        self::assertTrue($result->isOk());

        $result = $interceptor->handle($context, $handler);
        self::assertSame(GrpcStatus::ResourceExhausted, $result->status);
    }

    #[Test]
    public function errorMessageDoesNotContainMethodName(): void
    {
        $config = new RateLimitConfig(maxRequestsPerSecond: 1, burstSize: 1);
        $fullName = '/test.Service/TestMethod';
        $interceptor = new RateLimitInterceptor($config, static fn(): float => 1000.0);
        $context = $this->createContext($fullName);

        $handler = static fn(CallContext $ctx): InterceptorResult => InterceptorResult::ok('ok');

        // Exhaust
        $interceptor->handle($context, $handler);

        $result = $interceptor->handle($context, $handler);
        self::assertSame('Rate limit exceeded', $result->message);
        self::assertStringNotContainsString($fullName, $result->message);
    }

    #[Test]
    public function usesPerClientKeyingWithPeerIdentity(): void
    {
        $config = new RateLimitConfig(maxRequestsPerSecond: 100, burstSize: 1);
        $interceptor = new RateLimitInterceptor($config, static fn(): float => 1000.0);

        $handler = static fn(CallContext $ctx): InterceptorResult => InterceptorResult::ok('ok');

        // Client A exhausts its bucket
        $contextA = $this->createContext('/svc/Method', 'client-a');
        $result = $interceptor->handle($contextA, $handler);
        self::assertTrue($result->isOk());

        $result = $interceptor->handle($contextA, $handler);
        self::assertSame(GrpcStatus::ResourceExhausted, $result->status);

        // Client B on the same method should still have tokens
        $contextB = $this->createContext('/svc/Method', 'client-b');
        $result = $interceptor->handle($contextB, $handler);
        self::assertTrue($result->isOk());
    }

    #[Test]
    public function clockInjectionControlsRefill(): void
    {
        $time = 1000.0;
        $clock = static function () use (&$time): float {
            return $time;
        };

        $config = new RateLimitConfig(maxRequestsPerSecond: 10, burstSize: 1);
        $interceptor = new RateLimitInterceptor($config, $clock);
        $context = $this->createContext();

        $handler = static fn(CallContext $ctx): InterceptorResult => InterceptorResult::ok('ok');

        // Use the single token
        $result = $interceptor->handle($context, $handler);
        self::assertTrue($result->isOk());

        // No time passes: should be rejected
        $result = $interceptor->handle($context, $handler);
        self::assertSame(GrpcStatus::ResourceExhausted, $result->status);

        // Advance time by 1 second: should refill 10 tokens (capped to burstSize=1)
        $time = 1001.0;
        $result = $interceptor->handle($context, $handler);
        self::assertTrue($result->isOk());
    }

    private function createContext(
        string $fullName = '/helloworld.Greeter/SayHello',
        ?string $peerIdentity = null,
    ): CallContext {
        return new CallContext(
            method: new MethodDescriptor(
                name: 'SayHello',
                fullName: $fullName,
                type: MethodType::Unary,
                inputType: 'helloworld.HelloRequest',
                outputType: 'helloworld.HelloReply',
                handler: 'App\\Grpc\\GreeterHandler::sayHello',
            ),
            payload: '{}',
            peerIdentity: $peerIdentity,
        );
    }
}
