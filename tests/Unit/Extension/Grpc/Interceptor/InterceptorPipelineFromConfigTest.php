<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Grpc\Interceptor;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extension\Grpc\Config\GrpcConfig;
use Pulsar\Extension\Grpc\Interceptor\AuthInterceptor;
use Pulsar\Extension\Grpc\Interceptor\AuthValidatorInterface;
use Pulsar\Extension\Grpc\Interceptor\InterceptorPipeline;
use Pulsar\Extension\Grpc\Interceptor\LoggingInterceptor;
use Pulsar\Extension\Grpc\Interceptor\RateLimitInterceptor;
use Pulsar\Extension\Grpc\Interceptor\TracingInterceptor;
use Pulsar\Extension\Grpc\Interceptor\ValidationInterceptor;
use Pulsar\Extension\Grpc\Interceptor\ValidationRuleResolverInterface;
use Pulsar\Observability\Tracing\TraceContextParserInterface;

#[CoversClass(InterceptorPipeline::class)]
final class InterceptorPipelineFromConfigTest extends TestCase
{
    #[Test]
    public function fromConfigWithAllEnabled(): void
    {
        $config = GrpcConfig::fromArray([
            'interceptors' => [
                'tracing' => true,
                'auth' => true,
                'rate_limit' => true,
                'validation' => true,
                'logging' => true,
            ],
        ]);

        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturnCallback(static fn(string $id): bool => match ($id) {
            TraceContextParserInterface::class,
            AuthValidatorInterface::class,
            ValidationRuleResolverInterface::class => true,
            default => false,
        });
        $container->method('get')->willReturnCallback(function (string $id): mixed {
            return match ($id) {
                TraceContextParserInterface::class => $this->createStub(TraceContextParserInterface::class),
                AuthValidatorInterface::class => $this->createStub(AuthValidatorInterface::class),
                ValidationRuleResolverInterface::class => $this->createStub(ValidationRuleResolverInterface::class),
                default => null,
            };
        });

        $pipeline = InterceptorPipeline::fromConfig($config, $container);
        $interceptors = $pipeline->interceptors();

        self::assertCount(5, $interceptors);
        self::assertInstanceOf(TracingInterceptor::class, $interceptors[0]);
        self::assertInstanceOf(AuthInterceptor::class, $interceptors[1]);
        self::assertInstanceOf(RateLimitInterceptor::class, $interceptors[2]);
        self::assertInstanceOf(ValidationInterceptor::class, $interceptors[3]);
        self::assertInstanceOf(LoggingInterceptor::class, $interceptors[4]);
    }

    #[Test]
    public function fromConfigWithAllDisabled(): void
    {
        $config = GrpcConfig::fromArray([
            'interceptors' => [
                'tracing' => false,
                'auth' => false,
                'rate_limit' => false,
                'validation' => false,
                'logging' => false,
            ],
        ]);

        $container = $this->createStub(ContainerInterface::class);

        $pipeline = InterceptorPipeline::fromConfig($config, $container);

        self::assertTrue($pipeline->isEmpty());
    }

    #[Test]
    public function fromConfigSkipsTracingWhenParserMissing(): void
    {
        $config = GrpcConfig::fromArray([
            'interceptors' => [
                'tracing' => true,
                'auth' => false,
                'rate_limit' => false,
                'validation' => false,
                'logging' => false,
            ],
        ]);

        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturn(false);

        $pipeline = InterceptorPipeline::fromConfig($config, $container);

        self::assertTrue($pipeline->isEmpty());
    }

    #[Test]
    public function fromConfigSkipsAuthWhenValidatorMissing(): void
    {
        $config = GrpcConfig::fromArray([
            'interceptors' => [
                'tracing' => false,
                'auth' => true,
                'rate_limit' => false,
                'validation' => false,
                'logging' => false,
            ],
        ]);

        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturn(false);

        $pipeline = InterceptorPipeline::fromConfig($config, $container);

        self::assertTrue($pipeline->isEmpty());
    }

    #[Test]
    public function fromConfigOnlyRateLimitAndLogging(): void
    {
        $config = GrpcConfig::fromArray([
            'interceptors' => [
                'tracing' => false,
                'auth' => false,
                'rate_limit' => true,
                'validation' => false,
                'logging' => true,
            ],
        ]);

        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturn(false);

        $pipeline = InterceptorPipeline::fromConfig($config, $container);

        self::assertCount(2, $pipeline->interceptors());
        self::assertInstanceOf(RateLimitInterceptor::class, $pipeline->interceptors()[0]);
        self::assertInstanceOf(LoggingInterceptor::class, $pipeline->interceptors()[1]);
    }
}
