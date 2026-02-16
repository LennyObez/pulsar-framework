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
    public function constructionSetsAllProperties(): void
    {
        $now = new DateTimeImmutable();

        $event = new AuthenticationFailed(
            attemptedIdentity: 'unknown@example.com',
            guardName: 'session',
            failureReason: 'invalid_credentials',
            correlationId: 'corr-1',
            nonce: 'nonce456',
            occurredAt: $now,
        );

        self::assertSame('unknown@example.com', $event->attemptedIdentity);
        self::assertSame('session', $event->guardName);
        self::assertSame('invalid_credentials', $event->failureReason);
        self::assertSame('corr-1', $event->correlationId);
        self::assertSame('nonce456', $event->nonce);
        self::assertSame($now, $event->occurredAt);
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
