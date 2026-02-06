<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\WebAuthn\Authenticator;

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
    public function constructionPreservesAllFields(): void
    {
        $registeredAt = new DateTimeImmutable('2026-01-15T10:00:00+00:00');
        $lastUsedAt = new DateTimeImmutable('2026-02-10T14:30:00+00:00');

        $record = new AuthenticatorRecord(
            credentialId: 'cred-001',
            userId: 'user-42',
            displayName: 'MacBook Pro Touch ID',
            type: AuthenticatorType::Platform,
            aaguid: 'fbfc3007-154e-4ecc-8c0b-6e020557d7bd',
            active: true,
            registeredAt: $registeredAt,
            lastUsedAt: $lastUsedAt,
        );

        self::assertSame('cred-001', $record->credentialId);
        self::assertSame('user-42', $record->userId);
        self::assertSame('MacBook Pro Touch ID', $record->displayName);
        self::assertSame(AuthenticatorType::Platform, $record->type);
        self::assertSame('fbfc3007-154e-4ecc-8c0b-6e020557d7bd', $record->aaguid);
        self::assertTrue($record->active);
        self::assertSame($registeredAt, $record->registeredAt);
        self::assertSame($lastUsedAt, $record->lastUsedAt);
    }

    #[Test]
    public function lastUsedAtDefaultsToNull(): void
    {
        $record = new AuthenticatorRecord(
            credentialId: 'cred-002',
            userId: 'user-42',
            displayName: 'YubiKey 5',
            type: AuthenticatorType::CrossPlatform,
            aaguid: '00000000-0000-0000-0000-000000000000',
            active: true,
            registeredAt: new DateTimeImmutable(),
        );

        self::assertNull($record->lastUsedAt);
    }

    #[Test]
    public function inactiveAuthenticator(): void
    {
        $record = new AuthenticatorRecord(
            credentialId: 'cred-003',
            userId: 'user-42',
            displayName: 'Old Security Key',
            type: AuthenticatorType::CrossPlatform,
            aaguid: '00000000-0000-0000-0000-000000000000',
            active: false,
            registeredAt: new DateTimeImmutable('-1 year'),
        );

        self::assertFalse($record->active);
    }

    #[Test]
    public function crossPlatformAuthenticator(): void
    {
        $record = new AuthenticatorRecord(
            credentialId: 'cred-004',
            userId: 'user-42',
            displayName: 'YubiKey 5 NFC',
            type: AuthenticatorType::CrossPlatform,
            aaguid: 'cb69481e-8ff7-4039-93ec-0a2729a154a8',
            active: true,
            registeredAt: new DateTimeImmutable(),
        );

        self::assertSame(AuthenticatorType::CrossPlatform, $record->type);
    }
}
