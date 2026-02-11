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
    public function fromArrayDefaultsMissingFieldsToEmptyStrings(): void
    {
        $event = AuthenticationSucceeded::fromArray([]);

        self::assertSame('', $event->identityId);
        self::assertSame('', $event->guardName);
        self::assertSame('', $event->method);
        self::assertSame('', $event->correlationId);
        self::assertSame('', $event->nonce);
    }

    #[Test]
    public function fromArrayIgnoresNonStringValues(): void
    {
        $event = AuthenticationSucceeded::fromArray([
            'identity_id' => 42,
            'guard_name' => true,
            'method' => ['array'],
            'correlation_id' => null,
            'nonce' => 3.14,
        ]);

        self::assertSame('', $event->identityId);
        self::assertSame('', $event->guardName);
        self::assertSame('', $event->method);
        self::assertSame('', $event->correlationId);
        self::assertSame('', $event->nonce);
    }

    #[Test]
    public function toArrayIncludesSchemaVersion(): void
    {
        $now = new DateTimeImmutable('2025-06-15T10:30:00.000000+00:00');

        $event = new AuthenticationSucceeded(
            identityId: 'user-1',
            guardName: 'session',
            method: 'password',
            correlationId: 'c-1',
            nonce: 'n-1',
            occurredAt: $now,
        );

        $array = $event->toArray();

        self::assertSame(1, $array['schema_version']);
        self::assertSame('2025-06-15T10:30:00.000000+00:00', $array['occurred_at']);
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
