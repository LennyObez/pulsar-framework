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
use Pulsar\Extension\Grpc\Interceptor\ValidationInterceptor;
use Pulsar\Extension\Grpc\Interceptor\ValidationRuleResolverInterface;
use Pulsar\Http\Validation\RuleInterface;
use Pulsar\Http\Validation\Violation;

#[CoversClass(ValidationInterceptor::class)]
final class ValidationInterceptorTest extends TestCase
{
    #[Test]
    public function passesWhenNoRulesForMethod(): void
    {
        $resolver = $this->createStub(ValidationRuleResolverInterface::class);
        $resolver->method('rulesForMethod')->willReturn([]);

        $interceptor = new ValidationInterceptor($resolver);
        $context = $this->createContext('{"name":"test"}');

        $result = $interceptor->handle(
            $context,
            static fn(CallContext $ctx): InterceptorResult => InterceptorResult::ok('passed'),
        );

        self::assertTrue($result->isOk());
        self::assertSame('passed', $result->payload);
    }

    #[Test]
    public function passesWhenAllRulesPass(): void
    {
        $rule = $this->createStub(RuleInterface::class);
        $rule->method('validate')->willReturn(null);
        $rule->method('name')->willReturn('required');

        $resolver = $this->createStub(ValidationRuleResolverInterface::class);
        $resolver->method('rulesForMethod')->willReturn(['name' => [$rule]]);

        $interceptor = new ValidationInterceptor($resolver);
        $context = $this->createContext('{"name":"Alice"}');

        $result = $interceptor->handle(
            $context,
            static fn(CallContext $ctx): InterceptorResult => InterceptorResult::ok('valid'),
        );

        self::assertTrue($result->isOk());
    }

    #[Test]
    public function rejectsWhenValidationFails(): void
    {
        $rule = $this->createStub(RuleInterface::class);
        $rule->method('validate')->willReturn(
            new Violation('name', 'Name is required', 'required'),
        );
        $rule->method('name')->willReturn('required');

        $resolver = $this->createStub(ValidationRuleResolverInterface::class);
        $resolver->method('rulesForMethod')->willReturn(['name' => [$rule]]);

        $interceptor = new ValidationInterceptor($resolver);
        $context = $this->createContext('{"name":""}');

        $result = $interceptor->handle(
            $context,
            static fn(CallContext $ctx): InterceptorResult => InterceptorResult::ok('should not reach'),
        );

        self::assertSame(GrpcStatus::InvalidArgument, $result->status);
        self::assertStringContainsString('name', $result->message);
        self::assertStringContainsString('Name is required', $result->message);
    }

    #[Test]
    public function reportsMultipleViolations(): void
    {
        $nameRule = $this->createStub(RuleInterface::class);
        $nameRule->method('validate')->willReturn(
            new Violation('name', 'Name is required', 'required'),
        );

        $emailRule = $this->createStub(RuleInterface::class);
        $emailRule->method('validate')->willReturn(
            new Violation('email', 'Invalid email format', 'email'),
        );

        $resolver = $this->createStub(ValidationRuleResolverInterface::class);
        $resolver->method('rulesForMethod')->willReturn([
            'name' => [$nameRule],
            'email' => [$emailRule],
        ]);

        $interceptor = new ValidationInterceptor($resolver);
        $context = $this->createContext('{}');

        $result = $interceptor->handle(
            $context,
            static fn(CallContext $ctx): InterceptorResult => InterceptorResult::ok('should not reach'),
        );

        self::assertSame(GrpcStatus::InvalidArgument, $result->status);
        self::assertStringContainsString('2 violation(s)', $result->message);
        self::assertStringContainsString('Name is required', $result->message);
        self::assertStringContainsString('Invalid email format', $result->message);
    }

    #[Test]
    public function rejectsUnparsablePayload(): void
    {
        $rule = $this->createStub(RuleInterface::class);
        $rule->method('name')->willReturn('required');

        $resolver = $this->createStub(ValidationRuleResolverInterface::class);
        $resolver->method('rulesForMethod')->willReturn(['name' => [$rule]]);

        $interceptor = new ValidationInterceptor($resolver);
        $context = $this->createContext('not-valid-json{{{');

        $result = $interceptor->handle(
            $context,
            static fn(CallContext $ctx): InterceptorResult => InterceptorResult::ok('should not reach'),
        );

        self::assertSame(GrpcStatus::InvalidArgument, $result->status);
        self::assertStringContainsString('could not be deserialized', $result->message);
    }

    #[Test]
    public function handlesEmptyPayloadAsEmptyObject(): void
    {
        $rule = $this->createStub(RuleInterface::class);
        $rule->method('validate')->willReturn(null);
        $rule->method('name')->willReturn('optional');

        $resolver = $this->createStub(ValidationRuleResolverInterface::class);
        $resolver->method('rulesForMethod')->willReturn(['field' => [$rule]]);

        $interceptor = new ValidationInterceptor($resolver);
        $context = $this->createContext('');

        $result = $interceptor->handle(
            $context,
            static fn(CallContext $ctx): InterceptorResult => InterceptorResult::ok('ok'),
        );

        self::assertTrue($result->isOk());
    }

    #[Test]
    public function rejectsNonObjectJsonPayload(): void
    {
        $rule = $this->createStub(RuleInterface::class);
        $rule->method('name')->willReturn('required');

        $resolver = $this->createStub(ValidationRuleResolverInterface::class);
        $resolver->method('rulesForMethod')->willReturn(['name' => [$rule]]);

        $interceptor = new ValidationInterceptor($resolver);
        $context = $this->createContext('"just a string"');

        $result = $interceptor->handle(
            $context,
            static fn(CallContext $ctx): InterceptorResult => InterceptorResult::ok('should not reach'),
        );

        self::assertSame(GrpcStatus::InvalidArgument, $result->status);
        self::assertStringContainsString('could not be deserialized', $result->message);
    }

    #[Test]
    public function appliesMultipleRulesPerField(): void
    {
        $rule1 = $this->createStub(RuleInterface::class);
        $rule1->method('validate')->willReturn(null);
        $rule1->method('name')->willReturn('required');

        $rule2 = $this->createStub(RuleInterface::class);
        $rule2->method('validate')->willReturn(
            new Violation('name', 'Name must be at least 3 characters', 'min_length'),
        );
        $rule2->method('name')->willReturn('min_length');

        $resolver = $this->createStub(ValidationRuleResolverInterface::class);
        $resolver->method('rulesForMethod')->willReturn(['name' => [$rule1, $rule2]]);

        $interceptor = new ValidationInterceptor($resolver);
        $context = $this->createContext('{"name":"ab"}');

        $result = $interceptor->handle(
            $context,
            static fn(CallContext $ctx): InterceptorResult => InterceptorResult::ok('should not reach'),
        );

        self::assertSame(GrpcStatus::InvalidArgument, $result->status);
        self::assertStringContainsString('1 violation(s)', $result->message);
    }

    private function createContext(string $payload): CallContext
    {
        return new CallContext(
            method: new MethodDescriptor(
                name: 'CreateUser',
                fullName: '/users.UserService/CreateUser',
                type: MethodType::Unary,
                inputType: 'users.CreateUserRequest',
                outputType: 'users.CreateUserResponse',
                handler: 'App\\Grpc\\UserHandler::createUser',
            ),
            payload: $payload,
        );
    }
}
