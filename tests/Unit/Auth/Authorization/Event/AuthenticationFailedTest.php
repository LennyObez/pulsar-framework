<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Auth\Authorization\Event;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\Authorization\Event\AuthenticationFailed;

use function strlen;

#[CoversClass(AuthenticationFailed::class)]
final class AuthenticationFailedTest extends TestCase
{
    #[Test]
    public function fromArrayDefaultsMissingFieldsToEmptyStrings(): void
    {
        $event = AuthenticationFailed::fromArray([]);

        self::assertSame('', $event->attemptedIdentity);
        self::assertSame('', $event->guardName);
        self::assertSame('', $event->failureReason);
        self::assertSame('', $event->correlationId);
        self::assertSame('', $event->nonce);
    }

    #[Test]
    public function fromArrayIgnoresNonStringValues(): void
    {
        $event = AuthenticationFailed::fromArray([
            'attempted_identity' => 42,
            'guard_name' => true,
            'failure_reason' => ['array'],
            'correlation_id' => null,
            'nonce' => 3.14,
        ]);

        self::assertSame('', $event->attemptedIdentity);
        self::assertSame('', $event->guardName);
        self::assertSame('', $event->failureReason);
        self::assertSame('', $event->correlationId);
        self::assertSame('', $event->nonce);
    }

    #[Test]
    public function toArrayIncludesSchemaVersion(): void
    {
        $now = new DateTimeImmutable('2025-06-15T10:30:00.000000+00:00');

        $event = new AuthenticationFailed(
            attemptedIdentity: 'user@test.com',
            guardName: 'session',
            failureReason: 'locked',
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
        self::assertSame(1, AuthenticationFailed::SCHEMA_VERSION);
    }

    #[Test]
    public function toArrayFromArrayRoundTrip(): void
    {
        $now = new DateTimeImmutable('2025-06-15T10:30:00.000000+00:00');

        $event = new AuthenticationFailed(
            attemptedIdentity: 'unknown@example.com',
            guardName: 'token',
            failureReason: 'expired_token',
            correlationId: 'corr-1',
            nonce: 'nonce456',
            occurredAt: $now,
        );

        $array = $event->toArray();
        $restored = AuthenticationFailed::fromArray($array);

        self::assertSame($event->attemptedIdentity, $restored->attemptedIdentity);
        self::assertSame($event->guardName, $restored->guardName);
        self::assertSame($event->failureReason, $restored->failureReason);
        self::assertSame($event->correlationId, $restored->correlationId);
        self::assertSame($event->nonce, $restored->nonce);
    }

    #[Test]
    public function createGeneratesNonceAndTimestamp(): void
    {
        $event = AuthenticationFailed::create(
            attemptedIdentity: 'bad-user',
            guardName: 'session',
            failureReason: 'account_locked',
            correlationId: 'corr-2',
        );

        self::assertNotEmpty($event->nonce);
        self::assertSame(32, strlen($event->nonce));
    }
}
