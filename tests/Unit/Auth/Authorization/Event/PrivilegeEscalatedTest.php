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
    public function constructionSetsAllProperties(): void
    {
        $now = new DateTimeImmutable();

        $event = new PrivilegeEscalated(
            identityId: 'user-1',
            fromRoles: ['editor'],
            toRoles: ['editor', 'admin'],
            reason: 'emergency_access',
            correlationId: 'corr-1',
            nonce: 'nonce789',
            occurredAt: $now,
        );

        self::assertSame('user-1', $event->identityId);
        self::assertSame(['editor'], $event->fromRoles);
        self::assertSame(['editor', 'admin'], $event->toRoles);
        self::assertSame('emergency_access', $event->reason);
        self::assertSame('corr-1', $event->correlationId);
        self::assertSame('nonce789', $event->nonce);
        self::assertSame($now, $event->occurredAt);
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
