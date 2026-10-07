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
    public function fromArrayDefaultsMissingFieldsToEmptyStrings(): void
    {
        $event = AuthorizationDenied::fromArray([]);

        self::assertSame('', $event->identityId);
        self::assertSame('', $event->permission);
        self::assertNull($event->resource);
        self::assertSame('', $event->denialReason);
        self::assertSame('', $event->correlationId);
        self::assertSame('', $event->nonce);
    }

    #[Test]
    public function fromArrayIgnoresNonStringValues(): void
    {
        $event = AuthorizationDenied::fromArray([
            'identity_id' => 42,
            'permission' => true,
            'resource' => ['array'],
            'denial_reason' => null,
            'correlation_id' => 3.14,
            'nonce' => false,
        ]);

        self::assertSame('', $event->identityId);
        self::assertSame('', $event->permission);
        self::assertNull($event->resource);
        self::assertSame('', $event->denialReason);
        self::assertSame('', $event->correlationId);
        self::assertSame('', $event->nonce);
    }

    #[Test]
    public function toArrayIncludesSchemaVersion(): void
    {
        $now = new DateTimeImmutable('2025-06-15T10:30:00.000000+00:00');

        $event = new AuthorizationDenied(
            identityId: 'user-1',
            permission: 'users.delete',
            resource: 'user-42',
            denialReason: 'ABAC-deny',
            correlationId: 'c-1',
            nonce: 'n-1',
            occurredAt: $now,
        );

        $array = $event->toArray();

        self::assertSame(1, $array['schema_version']);
        self::assertSame('user-42', $array['resource']);
    }

    #[Test]
    public function createPreservesNullResource(): void
    {
        $event = AuthorizationDenied::create(
            identityId: 'user-1',
            permission: 'admin.panel',
            resource: null,
            denialReason: 'RBAC-miss',
            correlationId: 'corr-1',
        );

        self::assertNull($event->resource);

        $array = $event->toArray();
        self::assertNull($array['resource']);
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
