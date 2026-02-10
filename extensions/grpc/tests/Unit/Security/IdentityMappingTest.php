<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Grpc\Config\IdentityMappingEntry;
use Pulsar\Extension\Grpc\Security\IdentityMapping;
use Pulsar\Extension\Grpc\Security\ServiceIdentity;

#[CoversClass(IdentityMapping::class)]
final class IdentityMappingTest extends TestCase
{
    #[Test]
    public function lookupReturnsIdentityForKnownSan(): void
    {
        $mapping = new IdentityMapping([
            'api.example.com' => new IdentityMappingEntry(
                name: 'api-gateway',
                trustLevel: 'internal',
                allowedMethods: ['/billing.Invoice/Create'],
            ),
        ]);

        $identity = $mapping->lookup('api.example.com');

        self::assertInstanceOf(ServiceIdentity::class, $identity);
        self::assertSame('api-gateway', $identity->name);
        self::assertSame('internal', $identity->trustLevel);
        self::assertSame(['/billing.Invoice/Create'], $identity->allowedMethods);
    }

    #[Test]
    public function lookupReturnsNullForUnknownSan(): void
    {
        $mapping = new IdentityMapping([
            'known.example.com' => new IdentityMappingEntry(name: 'known'),
        ]);

        self::assertNull($mapping->lookup('unknown.example.com'));
    }

    #[Test]
    public function hasReturnsTrueForKnownSan(): void
    {
        $mapping = new IdentityMapping([
            'service.example.com' => new IdentityMappingEntry(name: 'svc'),
        ]);

        self::assertTrue($mapping->has('service.example.com'));
    }

    #[Test]
    public function hasReturnsFalseForUnknownSan(): void
    {
        $mapping = new IdentityMapping([
            'service.example.com' => new IdentityMappingEntry(name: 'svc'),
        ]);

        self::assertFalse($mapping->has('other.example.com'));
    }

    #[Test]
    public function sansReturnsAllConfiguredSans(): void
    {
        $mapping = new IdentityMapping([
            'alpha.example.com' => new IdentityMappingEntry(name: 'alpha'),
            'beta.example.com' => new IdentityMappingEntry(name: 'beta'),
        ]);

        self::assertSame(['alpha.example.com', 'beta.example.com'], $mapping->sans());
    }

    #[Test]
    public function identitiesReturnsAllCompiledIdentities(): void
    {
        $mapping = new IdentityMapping([
            'a.example.com' => new IdentityMappingEntry(name: 'service-a', trustLevel: 'internal'),
            'b.example.com' => new IdentityMappingEntry(name: 'service-b', trustLevel: 'external'),
        ]);

        $identities = $mapping->identities();

        self::assertCount(2, $identities);
        self::assertSame('service-a', $identities[0]->name);
        self::assertSame('service-b', $identities[1]->name);
    }

    #[Test]
    public function isEmptyReturnsTrueForEmptyMapping(): void
    {
        $mapping = new IdentityMapping([]);

        self::assertTrue($mapping->isEmpty());
    }

    #[Test]
    public function isEmptyReturnsFalseForNonEmptyMapping(): void
    {
        $mapping = new IdentityMapping([
            'svc.example.com' => new IdentityMappingEntry(name: 'svc'),
        ]);

        self::assertFalse($mapping->isEmpty());
    }

    #[Test]
    public function countReturnsNumberOfEntries(): void
    {
        $mapping = new IdentityMapping([
            'a.example.com' => new IdentityMappingEntry(name: 'a'),
            'b.example.com' => new IdentityMappingEntry(name: 'b'),
            'c.example.com' => new IdentityMappingEntry(name: 'c'),
        ]);

        self::assertSame(3, $mapping->count());
    }

    #[Test]
    public function countReturnsZeroForEmptyMapping(): void
    {
        $mapping = new IdentityMapping([]);

        self::assertSame(0, $mapping->count());
    }

    #[Test]
    public function compiledMappingIsImmutableAfterConstruction(): void
    {
        $entries = [
            'svc.example.com' => new IdentityMappingEntry(name: 'svc'),
        ];

        $mapping = new IdentityMapping($entries);

        // Original entries array modification does not affect the mapping
        $first = $mapping->lookup('svc.example.com');
        $second = $mapping->lookup('svc.example.com');

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertSame($first->name, $second->name);
    }
}
