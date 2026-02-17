<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Commerce;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Commerce\ApiKey;

#[CoversClass(ApiKey::class)]
final class ApiKeyTest extends TestCase
{
    #[Test]
    public function isExpiredReturnsFalseWhenNoExpiry(): void
    {
        $key = new ApiKey(
            id: 'key-001',
            tenantId: null,
            name: 'Production Key',
            keyHash: 'sha256hash',
            lastUsedAt: null,
            isActive: true,
            createdAt: new DateTimeImmutable(),
            expiresAt: null,
        );

        self::assertFalse($key->isExpired());
    }

    #[Test]
    public function isExpiredReturnsTrueWhenPastExpiry(): void
    {
        $key = new ApiKey(
            id: 'key-002',
            tenantId: null,
            name: 'Expired Key',
            keyHash: 'sha256hash',
            lastUsedAt: null,
            isActive: true,
            createdAt: new DateTimeImmutable('-60 days'),
            expiresAt: new DateTimeImmutable('-1 day'),
        );

        self::assertTrue($key->isExpired());
    }

    #[Test]
    public function isExpiredReturnsFalseWhenBeforeExpiry(): void
    {
        $key = new ApiKey(
            id: 'key-003',
            tenantId: 'tenant-01',
            name: 'Valid Key',
            keyHash: 'sha256hash',
            lastUsedAt: '2026-03-01T00:00:00Z',
            isActive: true,
            createdAt: new DateTimeImmutable('-30 days'),
            expiresAt: new DateTimeImmutable('+30 days'),
        );

        self::assertFalse($key->isExpired());
    }

    #[Test]
    public function constructSetsAllProperties(): void
    {
        $now = new DateTimeImmutable();
        $key = new ApiKey(
            id: 'key-004',
            tenantId: 'tenant-01',
            name: 'Test Key',
            keyHash: 'hash123',
            lastUsedAt: '2026-03-28T12:00:00Z',
            isActive: false,
            createdAt: $now,
            expiresAt: null,
        );

        self::assertSame('key-004', $key->id);
        self::assertSame('tenant-01', $key->tenantId);
        self::assertSame('Test Key', $key->name);
        self::assertSame('hash123', $key->keyHash);
        self::assertSame('2026-03-28T12:00:00Z', $key->lastUsedAt);
        self::assertFalse($key->isActive);
        self::assertSame($now, $key->createdAt);
        self::assertNull($key->expiresAt);
    }
}
