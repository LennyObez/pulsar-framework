<?php

declare(strict_types=1);

namespace Pulsar\Extension\WebAuthn\Tests\Unit\Authenticator;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\WebAuthn\Authenticator\AuthenticatorRecord;
use Pulsar\Extension\WebAuthn\Authenticator\AuthenticatorType;

#[CoversClass(AuthenticatorRecord::class)]
final class AuthenticatorRecordTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $registered = new DateTimeImmutable('2026-01-15');
        $lastUsed = new DateTimeImmutable('2026-03-28');

        $record = new AuthenticatorRecord(
            credentialId: 'cred-abc',
            userId: 'user-1',
            displayName: 'MacBook Touch ID',
            type: AuthenticatorType::Platform,
            aaguid: 'f8a011f3-8c0a-4d15-8006-17111f9edc7d',
            active: true,
            registeredAt: $registered,
            lastUsedAt: $lastUsed,
        );

        self::assertSame('cred-abc', $record->credentialId);
        self::assertSame('user-1', $record->userId);
        self::assertSame('MacBook Touch ID', $record->displayName);
        self::assertSame(AuthenticatorType::Platform, $record->type);
        self::assertSame('f8a011f3-8c0a-4d15-8006-17111f9edc7d', $record->aaguid);
        self::assertTrue($record->active);
        self::assertSame($registered, $record->registeredAt);
        self::assertSame($lastUsed, $record->lastUsedAt);
    }

    #[Test]
    public function lastUsedAtDefaultsToNull(): void
    {
        $record = new AuthenticatorRecord(
            credentialId: 'cred-xyz',
            userId: 'user-2',
            displayName: 'YubiKey 5',
            type: AuthenticatorType::CrossPlatform,
            aaguid: '00000000-0000-0000-0000-000000000000',
            active: false,
            registeredAt: new DateTimeImmutable(),
        );

        self::assertNull($record->lastUsedAt);
    }

    #[Test]
    public function inactiveRecordHasActiveFalse(): void
    {
        $record = new AuthenticatorRecord(
            credentialId: 'cred-inactive',
            userId: 'user-3',
            displayName: 'Revoked Key',
            type: AuthenticatorType::CrossPlatform,
            aaguid: '00000000-0000-0000-0000-000000000000',
            active: false,
            registeredAt: new DateTimeImmutable(),
        );

        self::assertFalse($record->active);
    }
}
