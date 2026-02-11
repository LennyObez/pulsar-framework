<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Grpc\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Grpc\Config\IdentityMappingEntry;
use Pulsar\Extension\Grpc\Security\GrpcSecurityEvent;
use Pulsar\Extension\Grpc\Security\IdentityMapping;
use Pulsar\Extension\Grpc\Security\ServiceIdentity;
use Pulsar\Extension\Grpc\Security\ServicePermission;

#[CoversClass(ServicePermission::class)]
#[CoversClass(ServiceIdentity::class)]
#[CoversClass(IdentityMapping::class)]
#[CoversClass(GrpcSecurityEvent::class)]
#[CoversClass(IdentityMappingEntry::class)]
final class GrpcSecurityTest extends TestCase
{
    // --- ServicePermission ---

    #[Test]
    public function unrestrictedPermissionAllowsAnyMethod(): void
    {
        $perm = ServicePermission::unrestricted();

        self::assertTrue($perm->allows('/test/Method'));
        self::assertTrue($perm->allows('/any/Other'));
        self::assertTrue($perm->isUnrestricted());
        self::assertSame(['*'], $perm->methods());
    }

    #[Test]
    public function restrictedPermissionOnlyAllowsListedMethods(): void
    {
        $perm = ServicePermission::restricted(['/test/Get', '/test/List']);

        self::assertTrue($perm->allows('/test/Get'));
        self::assertTrue($perm->allows('/test/List'));
        self::assertFalse($perm->allows('/test/Delete'));
        self::assertFalse($perm->isUnrestricted());
    }

    #[Test]
    public function emptyRestrictedPermissionAllowsNothing(): void
    {
        $perm = ServicePermission::restricted([]);

        self::assertFalse($perm->allows('/test/Get'));
        self::assertFalse($perm->isUnrestricted());
        self::assertSame([], $perm->methods());
    }

    // --- ServiceIdentity ---

    #[Test]
    public function serviceIdentityProperties(): void
    {
        $identity = new ServiceIdentity(
            name: 'billing-service',
            trustLevel: 'internal',
            allowedMethods: ['/billing/Charge', '/billing/Refund'],
        );

        self::assertSame('billing-service', $identity->name);
        self::assertSame('internal', $identity->trustLevel);
        self::assertTrue($identity->isMethodAllowed('/billing/Charge'));
        self::assertFalse($identity->isMethodAllowed('/billing/Delete'));
    }

    #[Test]
    public function serviceIdentityWithWildcard(): void
    {
        $identity = new ServiceIdentity(
            name: 'admin-service',
            trustLevel: 'internal',
            allowedMethods: ['*'],
        );

        self::assertTrue($identity->isMethodAllowed('/any/Method'));
        self::assertTrue($identity->permissions->isUnrestricted());
    }

    // --- IdentityMapping ---

    #[Test]
    public function identityMappingLookup(): void
    {
        $mapping = new IdentityMapping([
            'billing.example.com' => new IdentityMappingEntry(
                name: 'billing-service',
                trustLevel: 'internal',
                allowedMethods: ['*'],
            ),
        ]);

        $identity = $mapping->lookup('billing.example.com');

        self::assertNotNull($identity);
        self::assertSame('billing-service', $identity->name);
        self::assertSame('internal', $identity->trustLevel);
    }

    #[Test]
    public function identityMappingLookupReturnsNullForUnknownSan(): void
    {
        $mapping = new IdentityMapping([]);

        self::assertNull($mapping->lookup('unknown.com'));
    }

    #[Test]
    public function identityMappingHas(): void
    {
        $mapping = new IdentityMapping([
            'test.com' => new IdentityMappingEntry(name: 'test'),
        ]);

        self::assertTrue($mapping->has('test.com'));
        self::assertFalse($mapping->has('other.com'));
    }

    #[Test]
    public function identityMappingSansAndIdentities(): void
    {
        $mapping = new IdentityMapping([
            'a.com' => new IdentityMappingEntry(name: 'service-a'),
            'b.com' => new IdentityMappingEntry(name: 'service-b'),
        ]);

        self::assertSame(['a.com', 'b.com'], $mapping->sans());
        self::assertCount(2, $mapping->identities());
        self::assertSame(2, $mapping->count());
        self::assertFalse($mapping->isEmpty());
    }

    #[Test]
    public function emptyIdentityMapping(): void
    {
        $mapping = new IdentityMapping([]);

        self::assertTrue($mapping->isEmpty());
        self::assertSame(0, $mapping->count());
        self::assertSame([], $mapping->sans());
        self::assertSame([], $mapping->identities());
    }

    // --- IdentityMappingEntry ---

    #[Test]
    public function identityMappingEntryFromArray(): void
    {
        $entry = IdentityMappingEntry::fromArray([
            'name' => 'test-service',
            'trust_level' => 'partner',
            'allowed_methods' => ['/test/Method'],
        ]);

        self::assertSame('test-service', $entry->name);
        self::assertSame('partner', $entry->trustLevel);
        self::assertSame(['/test/Method'], $entry->allowedMethods);
    }

    #[Test]
    public function identityMappingEntryFromArrayDefaults(): void
    {
        $entry = IdentityMappingEntry::fromArray([]);

        self::assertSame('', $entry->name);
        self::assertSame('internal', $entry->trustLevel);
        self::assertSame(['*'], $entry->allowedMethods);
    }

    #[Test]
    public function identityMappingEntryFromArrayNonStringValues(): void
    {
        $entry = IdentityMappingEntry::fromArray([
            'name' => 123,
            'trust_level' => false,
            'allowed_methods' => 'not-array',
        ]);

        self::assertSame('', $entry->name);
        self::assertSame('internal', $entry->trustLevel);
        self::assertSame(['*'], $entry->allowedMethods);
    }

    #[Test]
    public function identityMappingEntryIsMethodAllowed(): void
    {
        $entry = new IdentityMappingEntry(
            name: 'svc',
            allowedMethods: ['/test/Get'],
        );

        self::assertTrue($entry->isMethodAllowed('/test/Get'));
        self::assertFalse($entry->isMethodAllowed('/test/Delete'));
    }

    #[Test]
    public function identityMappingEntryWildcardAllowsAll(): void
    {
        $entry = new IdentityMappingEntry(name: 'svc');

        self::assertTrue($entry->isMethodAllowed('/any/Method'));
    }

    // --- GrpcSecurityEvent ---

    #[Test]
    public function securityEventValues(): void
    {
        self::assertSame('grpc.reflection_enabled', GrpcSecurityEvent::GrpcReflectionEnabled->value);
        self::assertSame('grpc.mtls_authentication_failed', GrpcSecurityEvent::MtlsAuthenticationFailed->value);
        self::assertSame('grpc.unknown_client_certificate', GrpcSecurityEvent::UnknownClientCertificate->value);
        self::assertSame('grpc.authorization_denied', GrpcSecurityEvent::GrpcAuthorizationDenied->value);
        self::assertCount(4, GrpcSecurityEvent::cases());
    }
}
