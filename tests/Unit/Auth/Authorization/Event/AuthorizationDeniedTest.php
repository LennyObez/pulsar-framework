<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Auth\Authorization\Event;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\Authorization\Event\AuthorizationDenied;

use function strlen;

#[CoversClass(AuthorizationDenied::class)]
final class AuthorizationDeniedTest extends TestCase
{
    #[Test]
    public function constructionSetsAllProperties(): void
    {
        $now = new DateTimeImmutable();

        $event = new AuthorizationDenied(
            identityId: 'user-1',
            permission: 'users.delete',
            resource: 'user-42',
            denialReason: 'ABAC-deny',
            correlationId: 'corr-1',
            nonce: 'def456',
            occurredAt: $now,
        );

        self::assertSame('user-1', $event->identityId);
        self::assertSame('users.delete', $event->permission);
        self::assertSame('user-42', $event->resource);
        self::assertSame('ABAC-deny', $event->denialReason);
        self::assertSame('corr-1', $event->correlationId);
        self::assertSame('def456', $event->nonce);
        self::assertSame($now, $event->occurredAt);
    }

    #[Test]
    public function schemaVersionIsOne(): void
    {
        self::assertSame(1, AuthorizationDenied::SCHEMA_VERSION);
    }

    #[Test]
    public function toArrayFromArrayRoundTrip(): void
    {
        $now = new DateTimeImmutable('2025-06-15T10:30:00.000000+00:00');

        $event = new AuthorizationDenied(
            identityId: 'user-1',
            permission: 'users.delete',
            resource: null,
            denialReason: 'default-deny',
            correlationId: 'corr-1',
            nonce: 'def456',
            occurredAt: $now,
        );

        $array = $event->toArray();
        $restored = AuthorizationDenied::fromArray($array);

        self::assertSame($event->identityId, $restored->identityId);
        self::assertSame($event->permission, $restored->permission);
        self::assertSame($event->resource, $restored->resource);
        self::assertSame($event->denialReason, $restored->denialReason);
        self::assertSame($event->correlationId, $restored->correlationId);
        self::assertSame($event->nonce, $restored->nonce);
    }

    #[Test]
    public function createGeneratesNonceAndTimestamp(): void
    {
        $event = AuthorizationDenied::create(
            identityId: 'user-2',
            permission: 'admin.panel',
            resource: null,
            denialReason: 'RBAC-miss',
            correlationId: 'corr-2',
        );

        self::assertNotEmpty($event->nonce);
        self::assertSame(32, strlen($event->nonce));
    }
}
