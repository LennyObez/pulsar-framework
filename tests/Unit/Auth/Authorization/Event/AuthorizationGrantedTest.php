<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Auth\Authorization\Event;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\Authorization\Event\AuthorizationGranted;

use function strlen;

#[CoversClass(AuthorizationGranted::class)]
final class AuthorizationGrantedTest extends TestCase
{
    #[Test]
    public function fromArrayDefaultsMissingFieldsToEmptyStrings(): void
    {
        $event = AuthorizationGranted::fromArray([]);

        self::assertSame('', $event->identityId);
        self::assertSame('', $event->permission);
        self::assertNull($event->resource);
        self::assertSame('', $event->grantReason);
        self::assertSame('', $event->correlationId);
        self::assertSame('', $event->nonce);
    }

    #[Test]
    public function fromArrayIgnoresNonStringValues(): void
    {
        $event = AuthorizationGranted::fromArray([
            'identity_id' => 42,
            'permission' => true,
            'resource' => ['array'],
            'grant_reason' => null,
            'correlation_id' => 3.14,
            'nonce' => false,
        ]);

        self::assertSame('', $event->identityId);
        self::assertSame('', $event->permission);
        self::assertNull($event->resource);
        self::assertSame('', $event->grantReason);
        self::assertSame('', $event->correlationId);
        self::assertSame('', $event->nonce);
    }

    #[Test]
    public function toArrayIncludesSchemaVersion(): void
    {
        $now = new DateTimeImmutable('2025-06-15T10:30:00.000000+00:00');

        $event = new AuthorizationGranted(
            identityId: 'user-1',
            permission: 'posts.create',
            resource: 'post-42',
            grantReason: 'RBAC',
            correlationId: 'c-1',
            nonce: 'n-1',
            occurredAt: $now,
        );

        $array = $event->toArray();

        self::assertSame(1, $array['schema_version']);
        self::assertSame('post-42', $array['resource']);
    }

    #[Test]
    public function schemaVersionIsOne(): void
    {
        self::assertSame(1, AuthorizationGranted::SCHEMA_VERSION);
    }

    #[Test]
    public function toArrayFromArrayRoundTrip(): void
    {
        $now = new DateTimeImmutable('2025-06-15T10:30:00.000000+00:00');

        $event = new AuthorizationGranted(
            identityId: 'user-1',
            permission: 'posts.create',
            resource: 'post-42',
            grantReason: 'super-role',
            correlationId: 'corr-1',
            nonce: 'abc123',
            occurredAt: $now,
        );

        $array = $event->toArray();
        $restored = AuthorizationGranted::fromArray($array);

        self::assertSame($event->identityId, $restored->identityId);
        self::assertSame($event->permission, $restored->permission);
        self::assertSame($event->resource, $restored->resource);
        self::assertSame($event->grantReason, $restored->grantReason);
        self::assertSame($event->correlationId, $restored->correlationId);
        self::assertSame($event->nonce, $restored->nonce);
        self::assertSame(
            $event->occurredAt->format('Y-m-d\TH:i:s.uP'),
            $restored->occurredAt->format('Y-m-d\TH:i:s.uP'),
        );
    }

    #[Test]
    public function createGeneratesNonceAndTimestamp(): void
    {
        $event = AuthorizationGranted::create(
            identityId: 'user-2',
            permission: 'users.delete',
            resource: null,
            grantReason: 'ABAC',
            correlationId: 'corr-2',
        );

        self::assertSame('user-2', $event->identityId);
        self::assertSame('users.delete', $event->permission);
        self::assertNull($event->resource);
        self::assertSame('ABAC', $event->grantReason);
        self::assertNotEmpty($event->nonce);
        self::assertSame(32, strlen($event->nonce));
    }

    #[Test]
    public function resourceCanBeNull(): void
    {
        $event = AuthorizationGranted::create(
            identityId: 'user-3',
            permission: 'reports.view',
            resource: null,
            grantReason: 'RBAC',
            correlationId: 'corr-3',
        );

        self::assertNull($event->resource);

        $array = $event->toArray();
        self::assertNull($array['resource']);
    }
}
