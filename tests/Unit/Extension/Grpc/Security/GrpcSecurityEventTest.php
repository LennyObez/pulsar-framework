<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Grpc\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Grpc\Security\GrpcSecurityEvent;

#[CoversClass(GrpcSecurityEvent::class)]
final class GrpcSecurityEventTest extends TestCase
{
    #[Test]
    public function allCasesHaveStringValues(): void
    {
        self::assertSame('grpc.reflection_enabled', GrpcSecurityEvent::GrpcReflectionEnabled->value);
        self::assertSame('grpc.mtls_authentication_failed', GrpcSecurityEvent::MtlsAuthenticationFailed->value);
        self::assertSame('grpc.unknown_client_certificate', GrpcSecurityEvent::UnknownClientCertificate->value);
        self::assertSame('grpc.authorization_denied', GrpcSecurityEvent::GrpcAuthorizationDenied->value);
    }

    #[Test]
    public function allCasesExist(): void
    {
        self::assertCount(4, GrpcSecurityEvent::cases());
    }
}
