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
use Pulsar\Extension\Grpc\Interceptor\InterceptorResult;
use Pulsar\Extension\Grpc\Interceptor\ValidationInterceptor;
use Pulsar\Extension\Grpc\Interceptor\ValidationRuleResolverInterface;
use Pulsar\Http\Validation\RuleInterface;
use Pulsar\Http\Validation\Violation;

#[CoversClass(ValidationInterceptor::class)]
final class ValidationInterceptorTest extends TestCase
{
    private function makeContext(string $payload = ''): CallContext
    {
        return new CallContext(
            method: new MethodDescriptor(
                name: 'Create',
                fullName: '/test.Service/Create',
                type: MethodType::Unary,
                inputType: 'CreateReq',
                outputType: 'CreateRes',
                handler: 'Service::create',
            ),
            payload: $payload,
        );
    }

    private function passThrough(): Closure
    {
        return static fn(CallContext $ctx): InterceptorResult => InterceptorResult::ok('ok');
    }

    #[Test]
    public function passesWhenNoRulesExist(): void
    {
        $resolver = $this->createStub(ValidationRuleResolverInterface::class);
        $resolver->method('rulesForMethod')->willReturn([]);

        $interceptor = new ValidationInterceptor($resolver);

        $result = $interceptor->handle($this->makeContext(), $this->passThrough());

        self::assertTrue($result->isOk());
    }

    #[Test]
    public function passesWhenValidationSucceeds(): void
    {
        $rule = $this->createStub(RuleInterface::class);
        $rule->method('validate')->willReturn(null);

        $resolver = $this->createStub(ValidationRuleResolverInterface::class);
        $resolver->method('rulesForMethod')->willReturn(['name' => [$rule]]);

        $interceptor = new ValidationInterceptor($resolver);

        $result = $interceptor->handle(
            $this->makeContext('{"name":"valid"}'),
            $this->passThrough(),
        );

        self::assertTrue($result->isOk());
    }

    #[Test]
    public function rejectsWithInvalidArgumentOnViolation(): void
    {
        $rule = $this->createStub(RuleInterface::class);
        $rule->method('validate')->willReturn(new Violation('name', 'Name is required', 'required'));

        $resolver = $this->createStub(ValidationRuleResolverInterface::class);
        $resolver->method('rulesForMethod')->willReturn(['name' => [$rule]]);

        $interceptor = new ValidationInterceptor($resolver);

        $result = $interceptor->handle(
            $this->makeContext('{"name":""}'),
            $this->passThrough(),
        );

        self::assertSame(GrpcStatus::InvalidArgument, $result->status);
        self::assertStringContainsString('Name is required', $result->message);
        self::assertStringContainsString('1 violation', $result->message);
    }

    #[Test]
    public function rejectsInvalidJsonPayload(): void
    {
        $rule = $this->createStub(RuleInterface::class);

        $resolver = $this->createStub(ValidationRuleResolverInterface::class);
        $resolver->method('rulesForMethod')->willReturn(['field' => [$rule]]);

        $interceptor = new ValidationInterceptor($resolver);

        $result = $interceptor->handle(
            $this->makeContext('not-json'),
            $this->passThrough(),
        );

        self::assertSame(GrpcStatus::InvalidArgument, $result->status);
        self::assertStringContainsString('could not be deserialized', $result->message);
    }

    #[Test]
    public function rejectsNonArrayJsonPayload(): void
    {
        $rule = $this->createStub(RuleInterface::class);

        $resolver = $this->createStub(ValidationRuleResolverInterface::class);
        $resolver->method('rulesForMethod')->willReturn(['field' => [$rule]]);

        $interceptor = new ValidationInterceptor($resolver);

        $result = $interceptor->handle(
            $this->makeContext('"just a string"'),
            $this->passThrough(),
        );

        self::assertSame(GrpcStatus::InvalidArgument, $result->status);
    }

    #[Test]
    public function handlesEmptyPayloadAsEmptyArray(): void
    {
        $rule = $this->createStub(RuleInterface::class);
        $rule->method('validate')->willReturn(new Violation('name', 'required', 'required'));

        $resolver = $this->createStub(ValidationRuleResolverInterface::class);
        $resolver->method('rulesForMethod')->willReturn(['name' => [$rule]]);

        $interceptor = new ValidationInterceptor($resolver);

        $result = $interceptor->handle(
            $this->makeContext(''),
            $this->passThrough(),
        );

        self::assertSame(GrpcStatus::InvalidArgument, $result->status);
    }
}
