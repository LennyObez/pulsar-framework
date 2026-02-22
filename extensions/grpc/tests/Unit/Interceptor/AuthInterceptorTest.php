<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Tests\Unit\Interceptor;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
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
use Pulsar\Extension\Grpc\Security\GrpcSecurityEvent;
use Pulsar\Extension\Grpc\Security\MtlsIdentityMapper;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

#[CoversClass(AuthInterceptor::class)]
final class AuthInterceptorTest extends TestCase
{
    #[Test]
    public function authenticatesWithValidBearerToken(): void
    {
        $validator = $this->createStub(AuthValidatorInterface::class);
        $validator->method('validateToken')->willReturn('user-123');

        $interceptor = new AuthInterceptor($validator);
        $context = $this->createContext(['authorization' => ['Bearer valid-token']]);

        $capturedIdentity = null;
        $result = $interceptor->handle($context, static function (CallContext $ctx) use (&$capturedIdentity): InterceptorResult {
            $capturedIdentity = $ctx->attributes['auth.identity'] ?? null;

            return InterceptorResult::ok('done');
        });

        self::assertTrue($result->isOk());
        self::assertSame('user-123', $capturedIdentity);
    }

    #[Test]
    public function rejectsInvalidBearerToken(): void
    {
        $validator = $this->createStub(AuthValidatorInterface::class);
        $validator->method('validateToken')->willReturn(null);

        $interceptor = new AuthInterceptor($validator);
        $context = $this->createContext(['authorization' => ['Bearer invalid-token']]);

        $result = $interceptor->handle(
            $context,
            static fn(CallContext $ctx): InterceptorResult => InterceptorResult::ok('should not reach'),
        );

        self::assertSame(GrpcStatus::Unauthenticated, $result->status);
    }

    #[Test]
    public function rejectsNonBearerAuthScheme(): void
    {
        $validator = $this->createStub(AuthValidatorInterface::class);

        $interceptor = new AuthInterceptor($validator);
        $context = $this->createContext(['authorization' => ['Basic dXNlcjpwYXNz']]);

        $result = $interceptor->handle(
            $context,
            static fn(CallContext $ctx): InterceptorResult => InterceptorResult::ok('should not reach'),
        );

        self::assertSame(GrpcStatus::Unauthenticated, $result->status);
    }

    #[Test]
    public function rejectsEmptyBearerToken(): void
    {
        $validator = $this->createStub(AuthValidatorInterface::class);

        $interceptor = new AuthInterceptor($validator);
        $context = $this->createContext(['authorization' => ['Bearer ']]);

        $result = $interceptor->handle(
            $context,
            static fn(CallContext $ctx): InterceptorResult => InterceptorResult::ok('should not reach'),
        );

        self::assertSame(GrpcStatus::Unauthenticated, $result->status);
    }

    #[Test]
    public function fallsBackToMtlsPeerIdentity(): void
    {
        $validator = $this->createStub(AuthValidatorInterface::class);

        $interceptor = new AuthInterceptor($validator);
        $context = $this->createContext([], 'service.example.com');

        $capturedIdentity = null;
        $result = $interceptor->handle($context, static function (CallContext $ctx) use (&$capturedIdentity): InterceptorResult {
            $capturedIdentity = $ctx->attributes['auth.identity'] ?? null;

            return InterceptorResult::ok('done');
        });

        self::assertTrue($result->isOk());
        self::assertSame('service.example.com', $capturedIdentity);
    }

    #[Test]
    public function prefersTokenOverMtls(): void
    {
        $validator = $this->createStub(AuthValidatorInterface::class);
        $validator->method('validateToken')->willReturn('token-identity');

        $interceptor = new AuthInterceptor($validator);
        $context = $this->createContext(
            ['authorization' => ['Bearer valid']],
            'mtls-identity',
        );

        $capturedIdentity = null;
        $result = $interceptor->handle($context, static function (CallContext $ctx) use (&$capturedIdentity): InterceptorResult {
            $capturedIdentity = $ctx->attributes['auth.identity'] ?? null;

            return InterceptorResult::ok('done');
        });

        self::assertTrue($result->isOk());
        self::assertSame('token-identity', $capturedIdentity);
    }

    #[Test]
    public function rejectsWhenNoCredentials(): void
    {
        $validator = $this->createStub(AuthValidatorInterface::class);

        $interceptor = new AuthInterceptor($validator);
        $context = $this->createContext();

        $result = $interceptor->handle(
            $context,
            static fn(CallContext $ctx): InterceptorResult => InterceptorResult::ok('should not reach'),
        );

        self::assertSame(GrpcStatus::Unauthenticated, $result->status);
        self::assertStringContainsString('No valid authentication', $result->message);
    }

    #[Test]
    public function fallsBackToMtlsWhenTokenValidationFails(): void
    {
        $validator = $this->createStub(AuthValidatorInterface::class);
        $validator->method('validateToken')->willReturn(null);

        $interceptor = new AuthInterceptor($validator);
        $context = $this->createContext(
            ['authorization' => ['Bearer bad-token']],
            'mtls-fallback',
        );

        $capturedIdentity = null;
        $result = $interceptor->handle($context, static function (CallContext $ctx) use (&$capturedIdentity): InterceptorResult {
            $capturedIdentity = $ctx->attributes['auth.identity'] ?? null;

            return InterceptorResult::ok('done');
        });

        self::assertTrue($result->isOk());
        self::assertSame('mtls-fallback', $capturedIdentity);
    }

    #[Test]
    public function deniesAccessWhenMethodNotAllowed(): void
    {
        $validator = $this->createStub(AuthValidatorInterface::class);
        $mapper = new MtlsIdentityMapper([
            'payment.internal.example.com' => new IdentityMappingEntry(
                name: 'payment-service',
                trustLevel: 'internal',
                allowedMethods: ['/billing.Invoice/Create'],
            ),
        ]);

        $interceptor = new AuthInterceptor($validator, $mapper);
        $context = $this->createContext([], 'payment.internal.example.com');

        $result = $interceptor->handle(
            $context,
            static fn(CallContext $ctx): InterceptorResult => InterceptorResult::ok('should not reach'),
        );

        self::assertSame(GrpcStatus::PermissionDenied, $result->status);
        self::assertSame('Access denied', $result->message);
    }

    #[Test]
    public function allowsAccessWhenMethodIsInAllowedList(): void
    {
        $validator = $this->createStub(AuthValidatorInterface::class);
        $mapper = new MtlsIdentityMapper([
            'service.example.com' => new IdentityMappingEntry(
                name: 'greeter-service',
                trustLevel: 'internal',
                allowedMethods: ['/helloworld.Greeter/SayHello'],
            ),
        ]);

        $interceptor = new AuthInterceptor($validator, $mapper);
        $context = $this->createContext([], 'service.example.com');

        $result = $interceptor->handle(
            $context,
            static fn(CallContext $ctx): InterceptorResult => InterceptorResult::ok('allowed'),
        );

        self::assertTrue($result->isOk());
    }

    #[Test]
    public function allowsAccessWithWildcardPermission(): void
    {
        $validator = $this->createStub(AuthValidatorInterface::class);
        $mapper = new MtlsIdentityMapper([
            'admin.example.com' => new IdentityMappingEntry(
                name: 'admin-service',
                trustLevel: 'internal',
                allowedMethods: ['*'],
            ),
        ]);

        $interceptor = new AuthInterceptor($validator, $mapper);
        $context = $this->createContext([], 'admin.example.com');

        $result = $interceptor->handle(
            $context,
            static fn(CallContext $ctx): InterceptorResult => InterceptorResult::ok('allowed'),
        );

        self::assertTrue($result->isOk());
    }

    #[Test]
    public function emitsAuthenticationFailedAuditEvent(): void
    {
        $validator = $this->createStub(AuthValidatorInterface::class);
        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::once())
            ->method('log')
            ->with(
                AuditEvent::SecurityEvent,
                AuditOutcome::Failure,
                null,
                GrpcSecurityEvent::MtlsAuthenticationFailed->value,
                '/helloworld.Greeter/SayHello',
                self::anything(),
            );

        $interceptor = new AuthInterceptor($validator, auditLogger: $auditLogger);
        $context = $this->createContext();

        $interceptor->handle(
            $context,
            static fn(CallContext $ctx): InterceptorResult => InterceptorResult::ok('should not reach'),
        );
    }

    #[Test]
    public function emitsAuthorizationDeniedAuditEvent(): void
    {
        $validator = $this->createStub(AuthValidatorInterface::class);
        $mapper = new MtlsIdentityMapper([
            'restricted.example.com' => new IdentityMappingEntry(
                name: 'restricted',
                trustLevel: 'external',
                allowedMethods: ['/other.Service/Method'],
            ),
        ]);

        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::once())
            ->method('log')
            ->with(
                AuditEvent::SecurityEvent,
                AuditOutcome::Failure,
                'restricted.example.com',
                GrpcSecurityEvent::GrpcAuthorizationDenied->value,
                '/helloworld.Greeter/SayHello',
                self::anything(),
            );

        $interceptor = new AuthInterceptor($validator, $mapper, $auditLogger);
        $context = $this->createContext([], 'restricted.example.com');

        $interceptor->handle(
            $context,
            static fn(CallContext $ctx): InterceptorResult => InterceptorResult::ok('should not reach'),
        );
    }

    /**
     * @param array<string, list<string>> $metadata
     */
    private function createContext(array $metadata = [], ?string $peerIdentity = null): CallContext
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
            peerIdentity: $peerIdentity,
        );
    }
}
