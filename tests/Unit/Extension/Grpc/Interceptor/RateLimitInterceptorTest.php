<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Grpc\Interceptor;

use Closure;
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
    private function makeContext(?string $peerIdentity = null): CallContext
    {
        return new CallContext(
            method: new MethodDescriptor(
                name: 'Test',
                fullName: '/test.Service/Test',
                type: MethodType::Unary,
                inputType: 'Req',
                outputType: 'Res',
                handler: 'Handler::test',
            ),
            payload: '',
            peerIdentity: $peerIdentity,
        );
    }

    private function passThrough(): Closure
    {
        return static fn(CallContext $ctx): InterceptorResult => InterceptorResult::ok('ok');
    }

    #[Test]
    public function allowsRequestsWithinBurstLimit(): void
    {
        $config = new RateLimitConfig(maxRequestsPerSecond: 10, burstSize: 5);
        $time = 1000.0;
        $interceptor = new RateLimitInterceptor($config, static fn(): float => $time);

        for ($i = 0; $i < 5; $i++) {
            $result = $interceptor->handle($this->makeContext(), $this->passThrough());
            self::assertTrue($result->isOk(), "Request $i should pass");
        }
    }

    #[Test]
    public function rejectsRequestsExceedingBurstLimit(): void
    {
        $config = new RateLimitConfig(maxRequestsPerSecond: 10, burstSize: 2);
        $time = 1000.0;
        $interceptor = new RateLimitInterceptor($config, static fn(): float => $time);

        $interceptor->handle($this->makeContext(), $this->passThrough());
        $interceptor->handle($this->makeContext(), $this->passThrough());
        $result = $interceptor->handle($this->makeContext(), $this->passThrough());

        self::assertFalse($result->isOk());
        self::assertSame(GrpcStatus::ResourceExhausted, $result->status);
        self::assertSame('Rate limit exceeded', $result->message);
    }

    #[Test]
    public function refillsTokensOverTime(): void
    {
        $config = new RateLimitConfig(maxRequestsPerSecond: 10, burstSize: 2);
        $time = 1000.0;
        $interceptor = new RateLimitInterceptor($config, static function () use (&$time): float {
            return $time;
        });

        // Exhaust burst
        $interceptor->handle($this->makeContext(), $this->passThrough());
        $interceptor->handle($this->makeContext(), $this->passThrough());

        // Advance time to refill 1 token (0.1 sec at 10/sec = 1 token)
        $time += 0.1;

        $result = $interceptor->handle($this->makeContext(), $this->passThrough());
        self::assertTrue($result->isOk());
    }

    #[Test]
    public function separatesBucketsPerPeerIdentity(): void
    {
        $config = new RateLimitConfig(maxRequestsPerSecond: 10, burstSize: 1);
        $time = 1000.0;
        $interceptor = new RateLimitInterceptor($config, static fn(): float => $time);

        $result1 = $interceptor->handle($this->makeContext('client-a'), $this->passThrough());
        $result2 = $interceptor->handle($this->makeContext('client-b'), $this->passThrough());

        self::assertTrue($result1->isOk());
        self::assertTrue($result2->isOk());
    }

    #[Test]
    public function tokensBucketCapsAtBurstSize(): void
    {
        $config = new RateLimitConfig(maxRequestsPerSecond: 100, burstSize: 3);
        $time = 1000.0;
        $interceptor = new RateLimitInterceptor($config, static function () use (&$time): float {
            return $time;
        });

        // Use 1 token
        $interceptor->handle($this->makeContext(), $this->passThrough());

        // Advance significantly to accumulate many tokens — should cap at burstSize
        $time += 100.0;

        // Should be able to use exactly burstSize tokens
        for ($i = 0; $i < 3; $i++) {
            $result = $interceptor->handle($this->makeContext(), $this->passThrough());
            self::assertTrue($result->isOk(), "Request $i should pass after refill");
        }

        $result = $interceptor->handle($this->makeContext(), $this->passThrough());
        self::assertFalse($result->isOk());
    }
}
