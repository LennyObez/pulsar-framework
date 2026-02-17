<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\ZeroTrust\DeviceIdentity;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\ZeroTrust\DeviceIdentity\DeviceIdentity;
use Pulsar\Security\ZeroTrust\DeviceIdentity\DeviceProofResult;

#[CoversClass(DeviceIdentity::class)]
#[CoversClass(DeviceProofResult::class)]
final class DeviceValueObjectTest extends TestCase
{
    // ── DeviceIdentity ────────────────────────────────────────────────

    #[Test]
    public function deviceIdentityStoresAllProperties(): void
    {
        $registered = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $lastVerified = new DateTimeImmutable('2026-03-15T10:00:00+00:00');

        $device = new DeviceIdentity(
            deviceId: 'dev-1',
            identityId: 'user-42',
            fingerprint: 'abc123hash',
            attestationType: 'webauthn',
            publicKey: 'MFkwEwY...',
            registeredAt: $registered,
            lastVerifiedAt: $lastVerified,
            metadata: ['os' => 'macOS', 'browser' => 'Safari'],
        );

        self::assertSame('dev-1', $device->deviceId);
        self::assertSame('user-42', $device->identityId);
        self::assertSame('abc123hash', $device->fingerprint);
        self::assertSame('webauthn', $device->attestationType);
        self::assertSame('MFkwEwY...', $device->publicKey);
        self::assertSame($registered, $device->registeredAt);
        self::assertSame($lastVerified, $device->lastVerifiedAt);
        self::assertSame('macOS', $device->metadata['os']);
    }

    #[Test]
    public function deviceIdentityDefaultsToNullLastVerified(): void
    {
        $device = new DeviceIdentity(
            deviceId: 'dev-2',
            identityId: 'user-1',
            fingerprint: 'fp',
            attestationType: 'client_cert',
            publicKey: 'key',
            registeredAt: new DateTimeImmutable(),
        );

        self::assertNull($device->lastVerifiedAt);
        self::assertSame([], $device->metadata);
    }

    // ── DeviceProofResult ─────────────────────────────────────────────

    #[Test]
    public function verifiedFactoryCreatesSuccessResult(): void
    {
        $result = DeviceProofResult::verified('dev-42', 0.95);

        self::assertTrue($result->verified);
        self::assertSame(0.95, $result->confidence);
        self::assertSame('dev-42', $result->deviceId);
        self::assertSame('', $result->reason);
    }

    #[Test]
    public function verifiedFactoryDefaultsToFullConfidence(): void
    {
        $result = DeviceProofResult::verified('dev-1');

        self::assertSame(1.0, $result->confidence);
    }

    #[Test]
    public function failedFactoryCreatesFailureResult(): void
    {
        $result = DeviceProofResult::failed('Certificate expired');

        self::assertFalse($result->verified);
        self::assertSame(0.0, $result->confidence);
        self::assertSame('Certificate expired', $result->reason);
        self::assertSame('', $result->deviceId);
    }

    #[Test]
    public function failedFactoryWithCustomConfidence(): void
    {
        $result = DeviceProofResult::failed('Partial match', 0.3);

        self::assertFalse($result->verified);
        self::assertSame(0.3, $result->confidence);
    }
}
