<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Grpc\Interceptor;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Extension\Grpc\Config\IdentityMappingEntry;
use Pulsar\Extension\Grpc\Error\GrpcStatus;
use Pulsar\Extension\Grpc\Handler\MethodDescriptor;
use Pulsar\Extension\Grpc\Handler\MethodType;
use Pulsar\Extension\Grpc\Interceptor\AuthInterceptor;
use Pulsar\Extension\Grpc\Interceptor\AuthValidatorInterface;
use Pulsar\Extension\Grpc\Interceptor\CallContext;
use Pulsar\Extension\Grpc\Interceptor\InterceptorResult;
use Pulsar\Extension\Grpc\Security\MtlsIdentityMapper;
use Pulsar\Security\Audit\AuditEntry;

#[CoversClass(AuthInterceptor::class)]
final class AuthInterceptorTest extends TestCase
{
    private function makeMethod(): MethodDescriptor
    {
        return new MethodDescriptor(
            name: 'GetUser',
            fullName: '/users.UserService/GetUser',
            type: MethodType::Unary,
            inputType: 'GetUserReq',
            outputType: 'GetUserRes',
            handler: 'UserService::getUser',
        );
    }

    private function passThrough(): Closure
    {
        return static fn(CallContext $ctx): InterceptorResult => InterceptorResult::ok('ok');
    }

    #[Test]
    public function authenticatesViaBearerToken(): void
    {
        $validator = $this->createStub(AuthValidatorInterface::class);
        $validator->method('validateToken')->willReturn('user-42');

        $interceptor = new AuthInterceptor($validator);

        $context = new CallContext(
            method: $this->makeMethod(),
            payload: '',
            metadata: ['authorization' => ['Bearer my-jwt-token']],
        );

        $result = $interceptor->handle($context, $this->passThrough());

        self::assertTrue($result->isOk());
    }

    #[Test]
    public function rejectsEmptyBearerToken(): void
    {
        $validator = $this->createStub(AuthValidatorInterface::class);
        $validator->method('validateToken')->willReturn(null);

        $interceptor = new AuthInterceptor($validator);

        $context = new CallContext(
            method: $this->makeMethod(),
            payload: '',
            metadata: ['authorization' => ['Bearer ']],
        );

        $result = $interceptor->handle($context, $this->passThrough());

        self::assertSame(GrpcStatus::Unauthenticated, $result->status);
    }

    #[Test]
    public function rejectsNonBearerAuthHeader(): void
    {
        $validator = $this->createStub(AuthValidatorInterface::class);
        $validator->method('validateToken')->willReturn(null);

        $interceptor = new AuthInterceptor($validator);

        $context = new CallContext(
            method: $this->makeMethod(),
            payload: '',
            metadata: ['authorization' => ['Basic dXNlcjpwYXNz']],
        );

        $result = $interceptor->handle($context, $this->passThrough());

        self::assertSame(GrpcStatus::Unauthenticated, $result->status);
    }

    #[Test]
    public function fallsBackToMtlsPeerIdentity(): void
    {
        $validator = $this->createStub(AuthValidatorInterface::class);
        $validator->method('validateToken')->willReturn(null);

        $interceptor = new AuthInterceptor($validator);

        $context = new CallContext(
            method: $this->makeMethod(),
            payload: '',
            peerIdentity: 'service-a.internal',
        );

        $result = $interceptor->handle($context, $this->passThrough());

        self::assertTrue($result->isOk());
    }

    #[Test]
    public function rejectsWithNoCredentials(): void
    {
        $validator = $this->createStub(AuthValidatorInterface::class);

        $interceptor = new AuthInterceptor($validator);

        $context = new CallContext(
            method: $this->makeMethod(),
            payload: '',
        );

        $result = $interceptor->handle($context, $this->passThrough());

        self::assertSame(GrpcStatus::Unauthenticated, $result->status);
        self::assertStringContainsString('No valid authentication', $result->message);
    }

    #[Test]
    public function rejectsWithNoCredentialsAndAudits(): void
    {
        $validator = $this->createStub(AuthValidatorInterface::class);
        /** @var AuditLoggerInterface&Stub $auditLogger */
        $auditLogger = $this->createStub(AuditLoggerInterface::class);
        $auditLogger->method('log')->willReturn($this->createStub(AuditEntry::class));

        $interceptor = new AuthInterceptor($validator, auditLogger: $auditLogger);

        $context = new CallContext(
            method: $this->makeMethod(),
            payload: '',
        );

        $result = $interceptor->handle($context, $this->passThrough());

        self::assertSame(GrpcStatus::Unauthenticated, $result->status);
    }

    #[Test]
    public function deniesPermissionForUnauthorizedMethod(): void
    {
        $validator = $this->createStub(AuthValidatorInterface::class);
        $validator->method('validateToken')->willReturn(null);

        $mapper = new MtlsIdentityMapper([
            'service-a.internal' => new IdentityMappingEntry(
                name: 'service-a',
                trustLevel: 'internal',
                allowedMethods: ['/users.UserService/ListUsers'],
            ),
        ]);

        /** @var AuditLoggerInterface&Stub $auditLogger */
        $auditLogger = $this->createStub(AuditLoggerInterface::class);
        $auditLogger->method('log')->willReturn($this->createStub(AuditEntry::class));

        $interceptor = new AuthInterceptor($validator, $mapper, $auditLogger);

        $context = new CallContext(
            method: $this->makeMethod(),
            payload: '',
            peerIdentity: 'service-a.internal',
        );

        $result = $interceptor->handle($context, $this->passThrough());

        self::assertSame(GrpcStatus::PermissionDenied, $result->status);
    }

    #[Test]
    public function setsAuthIdentityAttribute(): void
    {
        $validator = $this->createStub(AuthValidatorInterface::class);
        $validator->method('validateToken')->willReturn('user-77');

        $interceptor = new AuthInterceptor($validator);

        $capturedCtx = null;
        $context = new CallContext(
            method: $this->makeMethod(),
            payload: '',
            metadata: ['authorization' => ['Bearer token']],
        );

        $interceptor->handle($context, static function (CallContext $ctx) use (&$capturedCtx): InterceptorResult {
            $capturedCtx = $ctx;
            return InterceptorResult::ok('ok');
        });

        self::assertInstanceOf(CallContext::class, $capturedCtx);
        self::assertSame('user-77', $capturedCtx->attributes['auth.identity']);
    }
}
