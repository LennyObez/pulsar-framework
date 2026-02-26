<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Auth\Authorization\Event;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\Authorization\Event\AuthenticationSucceeded;

use function strlen;

#[CoversClass(AuthenticationSucceeded::class)]
final class AuthenticationSucceededTest extends TestCase
{
    #[Test]
    public function constructionSetsAllProperties(): void
    {
        $now = new DateTimeImmutable();

        $event = new AuthenticationSucceeded(
            identityId: 'user-1',
            guardName: 'session',
            method: 'password',
            correlationId: 'corr-1',
            nonce: 'nonce123',
            occurredAt: $now,
        );

        self::assertSame('user-1', $event->identityId);
        self::assertSame('session', $event->guardName);
        self::assertSame('password', $event->method);
        self::assertSame('corr-1', $event->correlationId);
        self::assertSame('nonce123', $event->nonce);
        self::assertSame($now, $event->occurredAt);
    }

    #[Test]
    public function schemaVersionIsOne(): void
    {
        self::assertSame(1, AuthenticationSucceeded::SCHEMA_VERSION);
    }

    #[Test]
    public function toArrayFromArrayRoundTrip(): void
    {
        $now = new DateTimeImmutable('2025-06-15T10:30:00.000000+00:00');

        $event = new AuthenticationSucceeded(
            identityId: 'user-1',
            guardName: 'token',
            method: 'webauthn',
            correlationId: 'corr-1',
            nonce: 'nonce123',
            occurredAt: $now,
        );

        $array = $event->toArray();
        $restored = AuthenticationSucceeded::fromArray($array);

        self::assertSame($event->identityId, $restored->identityId);
        self::assertSame($event->guardName, $restored->guardName);
        self::assertSame($event->method, $restored->method);
        self::assertSame($event->correlationId, $restored->correlationId);
        self::assertSame($event->nonce, $restored->nonce);
    }

    #[Test]
    public function createGeneratesNonceAndTimestamp(): void
    {
        $event = AuthenticationSucceeded::create(
            identityId: 'user-2',
            guardName: 'session',
            method: 'token',
            correlationId: 'corr-2',
        );

        self::assertNotEmpty($event->nonce);
        self::assertSame(32, strlen($event->nonce));
    }
}
