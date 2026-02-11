<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Grpc\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Grpc\Config\IdentityMappingEntry;
use Pulsar\Extension\Grpc\Security\IdentityMapping;

#[CoversClass(IdentityMapping::class)]
final class IdentityMappingTest extends TestCase
{
    #[Test]
    public function emptyMapping(): void
    {
        $mapping = new IdentityMapping([]);

        self::assertTrue($mapping->isEmpty());
        self::assertSame(0, $mapping->count());
        self::assertSame([], $mapping->sans());
        self::assertSame([], $mapping->identities());
        self::assertNull($mapping->lookup('any.san'));
        self::assertFalse($mapping->has('any.san'));
    }

    #[Test]
    public function lookupReturnsMappedIdentity(): void
    {
        $entry = new IdentityMappingEntry(
            name: 'payment-service',
            trustLevel: 'internal',
            allowedMethods: ['/billing.Payment/Charge'],
        );

        $mapping = new IdentityMapping(['payment.example.com' => $entry]);

        self::assertFalse($mapping->isEmpty());
        self::assertSame(1, $mapping->count());
        self::assertTrue($mapping->has('payment.example.com'));

        $identity = $mapping->lookup('payment.example.com');
        self::assertNotNull($identity);
        self::assertSame('payment-service', $identity->name);
        self::assertSame('internal', $identity->trustLevel);
        self::assertTrue($identity->isMethodAllowed('/billing.Payment/Charge'));
    }

    #[Test]
    public function lookupReturnsNullForUnmappedSan(): void
    {
        $entry = new IdentityMappingEntry(
            name: 'svc',
            trustLevel: 'internal',
            allowedMethods: ['*'],
        );

        $mapping = new IdentityMapping(['known.san' => $entry]);

        self::assertNull($mapping->lookup('unknown.san'));
        self::assertFalse($mapping->has('unknown.san'));
    }

    #[Test]
    public function sansReturnsAllKeys(): void
    {
        $entry1 = new IdentityMappingEntry(name: 'a', trustLevel: 'internal', allowedMethods: ['*']);
        $entry2 = new IdentityMappingEntry(name: 'b', trustLevel: 'external', allowedMethods: ['*']);

        $mapping = new IdentityMapping([
            'san-a.example.com' => $entry1,
            'san-b.example.com' => $entry2,
        ]);

        self::assertSame(['san-a.example.com', 'san-b.example.com'], $mapping->sans());
        self::assertSame(2, $mapping->count());
    }

    #[Test]
    public function identitiesReturnsAllValues(): void
    {
        $entry = new IdentityMappingEntry(name: 'svc', trustLevel: 'internal', allowedMethods: ['*']);
        $mapping = new IdentityMapping(['san.example.com' => $entry]);

        $identities = $mapping->identities();

        self::assertCount(1, $identities);
        self::assertSame('svc', $identities[0]->name);
    }
}
