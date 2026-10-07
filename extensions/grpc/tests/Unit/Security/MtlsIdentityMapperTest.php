<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Extension\Grpc\Config\IdentityMappingEntry;
use Pulsar\Extension\Grpc\Security\GrpcSecurityEvent;
use Pulsar\Extension\Grpc\Security\MtlsIdentityMapper;
use Pulsar\Extension\Grpc\Security\ServiceIdentity;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

#[CoversClass(MtlsIdentityMapper::class)]
final class MtlsIdentityMapperTest extends TestCase
{
    #[Test]
    public function resolveReturnsIdentityForKnownSan(): void
    {
        $mapper = new MtlsIdentityMapper([
            'payment.internal.example.com' => new IdentityMappingEntry(
                name: 'payment-service',
                trustLevel: 'internal',
                allowedMethods: ['/billing.Invoice/Create'],
            ),
        ]);

        $identity = $mapper->resolve('payment.internal.example.com');

        self::assertInstanceOf(ServiceIdentity::class, $identity);
        self::assertSame('payment-service', $identity->name);
        self::assertSame('internal', $identity->trustLevel);
        self::assertSame(['/billing.Invoice/Create'], $identity->allowedMethods);
    }

    #[Test]
    public function resolveReturnsNullForUnknownSan(): void
    {
        $mapper = new MtlsIdentityMapper([
            'known.example.com' => new IdentityMappingEntry(name: 'known-service'),
        ]);

        self::assertNull($mapper->resolve('unknown.example.com'));
    }

    #[Test]
    public function resolveReturnsNullForEmptyMap(): void
    {
        $mapper = new MtlsIdentityMapper([]);

        self::assertNull($mapper->resolve('any.example.com'));
    }

    #[Test]
    public function resolveIsDeterministic(): void
    {
        $mapper = new MtlsIdentityMapper([
            'api.example.com' => new IdentityMappingEntry(
                name: 'api-gateway',
                trustLevel: 'partner',
                allowedMethods: ['*'],
            ),
        ]);

        $first = $mapper->resolve('api.example.com');
        $second = $mapper->resolve('api.example.com');

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertSame($first->name, $second->name);
        self::assertSame($first->trustLevel, $second->trustLevel);
        self::assertSame($first->allowedMethods, $second->allowedMethods);
    }

    #[Test]
    public function resolveDistinguishesBetweenMultipleSans(): void
    {
        $mapper = new MtlsIdentityMapper([
            'service-a.example.com' => new IdentityMappingEntry(
                name: 'service-a',
                trustLevel: 'internal',
                allowedMethods: ['/a.Service/Method'],
            ),
            'service-b.example.com' => new IdentityMappingEntry(
                name: 'service-b',
                trustLevel: 'external',
                allowedMethods: ['/b.Service/Method'],
            ),
        ]);

        $a = $mapper->resolve('service-a.example.com');
        $b = $mapper->resolve('service-b.example.com');

        self::assertNotNull($a);
        self::assertNotNull($b);
        self::assertSame('service-a', $a->name);
        self::assertSame('service-b', $b->name);
        self::assertSame('internal', $a->trustLevel);
        self::assertSame('external', $b->trustLevel);
    }

    #[Test]
    public function resolvePreservesDefaultsFromIdentityMappingEntry(): void
    {
        $mapper = new MtlsIdentityMapper([
            'default.example.com' => new IdentityMappingEntry(name: 'default-service'),
        ]);

        $identity = $mapper->resolve('default.example.com');

        self::assertNotNull($identity);
        self::assertSame('default-service', $identity->name);
        self::assertSame('internal', $identity->trustLevel);
        self::assertSame(['*'], $identity->allowedMethods);
    }

    #[Test]
    public function resolveSanIsCaseSensitive(): void
    {
        $mapper = new MtlsIdentityMapper([
            'api.example.com' => new IdentityMappingEntry(name: 'api'),
        ]);

        self::assertNotNull($mapper->resolve('api.example.com'));
        self::assertNull($mapper->resolve('API.EXAMPLE.COM'));
        self::assertNull($mapper->resolve('Api.Example.Com'));
    }

    #[Test]
    public function resolveEmitsUnknownClientCertificateEvent(): void
    {
        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::once())
            ->method('log')
            ->with(
                AuditEvent::SecurityEvent,
                AuditOutcome::Failure,
                'unknown.example.com',
                GrpcSecurityEvent::UnknownClientCertificate->value,
                'grpc.mtls',
                ['san' => 'unknown.example.com'],
            );

        $mapper = new MtlsIdentityMapper([
            'known.example.com' => new IdentityMappingEntry(name: 'known'),
        ], $auditLogger);

        $mapper->resolve('unknown.example.com');
    }

    #[Test]
    public function resolveDoesNotEmitEventForKnownSan(): void
    {
        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::never())->method('log');

        $mapper = new MtlsIdentityMapper([
            'known.example.com' => new IdentityMappingEntry(name: 'known'),
        ], $auditLogger);

        $mapper->resolve('known.example.com');
    }
}
