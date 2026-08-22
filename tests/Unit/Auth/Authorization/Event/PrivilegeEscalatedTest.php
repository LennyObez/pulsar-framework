<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Auth\Authorization\Event;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\Authorization\Event\PrivilegeEscalated;

use function strlen;

#[CoversClass(PrivilegeEscalated::class)]
final class PrivilegeEscalatedTest extends TestCase
{
    #[Test]
    public function fromArrayDefaultsMissingFieldsToEmptyValues(): void
    {
        $event = PrivilegeEscalated::fromArray([]);

        self::assertSame('', $event->identityId);
        self::assertSame([], $event->fromRoles);
        self::assertSame([], $event->toRoles);
        self::assertSame('', $event->reason);
        self::assertSame('', $event->correlationId);
        self::assertSame('', $event->nonce);
    }

    #[Test]
    public function fromArrayFiltersNonStringRoles(): void
    {
        $event = PrivilegeEscalated::fromArray([
            'identity_id' => 'user-1',
            'from_roles' => ['editor', 42, null, 'viewer'],
            'to_roles' => [true, 'admin', ['nested'], 'super'],
            'reason' => 'test',
            'correlation_id' => 'c-1',
            'nonce' => 'n-1',
            'occurred_at' => '2025-06-15T10:30:00.000000+00:00',
        ]);

        self::assertSame(['editor', 'viewer'], $event->fromRoles);
        self::assertSame(['admin', 'super'], $event->toRoles);
    }

    #[Test]
    public function toArrayIncludesSchemaVersion(): void
    {
        $now = new DateTimeImmutable('2025-06-15T10:30:00.000000+00:00');

        $event = new PrivilegeEscalated(
            identityId: 'user-1',
            fromRoles: ['viewer'],
            toRoles: ['admin'],
            reason: 'promotion',
            correlationId: 'c-1',
            nonce: 'n-1',
            occurredAt: $now,
        );

        $array = $event->toArray();

        self::assertSame(1, $array['schema_version']);
        self::assertSame(['viewer'], $array['from_roles']);
        self::assertSame(['admin'], $array['to_roles']);
    }

    #[Test]
    public function schemaVersionIsOne(): void
    {
        self::assertSame(1, PrivilegeEscalated::SCHEMA_VERSION);
    }

    #[Test]
    public function toArrayFromArrayRoundTrip(): void
    {
        $now = new DateTimeImmutable('2025-06-15T10:30:00.000000+00:00');

        $event = new PrivilegeEscalated(
            identityId: 'user-1',
            fromRoles: ['viewer'],
            toRoles: ['viewer', 'editor', 'admin'],
            reason: 'role_promotion',
            correlationId: 'corr-1',
            nonce: 'nonce789',
            occurredAt: $now,
        );

        $array = $event->toArray();
        $restored = PrivilegeEscalated::fromArray($array);

        self::assertSame($event->identityId, $restored->identityId);
        self::assertSame($event->fromRoles, $restored->fromRoles);
        self::assertSame($event->toRoles, $restored->toRoles);
        self::assertSame($event->reason, $restored->reason);
        self::assertSame($event->correlationId, $restored->correlationId);
        self::assertSame($event->nonce, $restored->nonce);
    }

    #[Test]
    public function createGeneratesNonceAndTimestamp(): void
    {
        $event = PrivilegeEscalated::create(
            identityId: 'user-2',
            fromRoles: [],
            toRoles: ['admin'],
            reason: 'onboarding',
            correlationId: 'corr-2',
        );

        self::assertNotEmpty($event->nonce);
        self::assertSame(32, strlen($event->nonce));
    }
}
