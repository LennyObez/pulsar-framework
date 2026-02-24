<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Grpc\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Extension\Grpc\Config\IdentityMappingEntry;
use Pulsar\Extension\Grpc\Security\MtlsIdentityMapper;

#[CoversClass(MtlsIdentityMapper::class)]
final class MtlsIdentityMapperTest extends TestCase
{
    #[Test]
    public function resolveReturnsIdentityForKnownSan(): void
    {
        $entry = new IdentityMappingEntry(
            name: 'auth-service',
            trustLevel: 'internal',
            allowedMethods: ['*'],
        );

        $mapper = new MtlsIdentityMapper(['auth.example.com' => $entry]);

        $identity = $mapper->resolve('auth.example.com');

        self::assertNotNull($identity);
        self::assertSame('auth-service', $identity->name);
    }

    #[Test]
    public function resolveReturnsNullForUnknownSan(): void
    {
        $mapper = new MtlsIdentityMapper([]);

        self::assertNull($mapper->resolve('unknown.san'));
    }

    #[Test]
    public function resolveEmitsAuditEventForUnknownSan(): void
    {
        /** @var AuditLoggerInterface&MockObject $audit */
        $audit = $this->createMock(AuditLoggerInterface::class);
        $audit->expects(self::once())->method('log')
            ->with(
                self::anything(),
                self::anything(),
                'evil.attacker.com',
                self::stringContains('unknown_client_certificate'),
            );

        $mapper = new MtlsIdentityMapper([], $audit);
        $mapper->resolve('evil.attacker.com');
    }

    #[Test]
    public function resolveDoesNotEmitAuditForKnownSan(): void
    {
        $entry = new IdentityMappingEntry(name: 'svc', trustLevel: 'internal', allowedMethods: ['*']);

        /** @var AuditLoggerInterface&MockObject $audit */
        $audit = $this->createMock(AuditLoggerInterface::class);
        $audit->expects(self::never())->method('log');

        $mapper = new MtlsIdentityMapper(['known.san' => $entry], $audit);
        $mapper->resolve('known.san');
    }

    #[Test]
    public function identityMappingExposesCompiledMapping(): void
    {
        $entry = new IdentityMappingEntry(name: 'svc', trustLevel: 'internal', allowedMethods: ['*']);
        $mapper = new MtlsIdentityMapper(['san.com' => $entry]);

        $mapping = $mapper->identityMapping();

        self::assertSame(1, $mapping->count());
        self::assertTrue($mapping->has('san.com'));
    }
}
