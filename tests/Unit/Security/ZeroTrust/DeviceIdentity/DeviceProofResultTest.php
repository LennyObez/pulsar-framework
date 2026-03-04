<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\ZeroTrust\DeviceIdentity;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\ZeroTrust\DeviceIdentity\DeviceProofResult;

final class DeviceProofResultTest extends TestCase
{
    #[Test]
    public function verified_factory_creates_success_result(): void
    {
        $result = DeviceProofResult::verified('device-42');

        self::assertTrue($result->verified);
        self::assertSame(1.0, $result->confidence);
        self::assertSame('device-42', $result->deviceId);
        self::assertSame('', $result->reason);
    }

    #[Test]
    public function verified_factory_accepts_custom_confidence(): void
    {
        $result = DeviceProofResult::verified('device-42', 0.85);

        self::assertTrue($result->verified);
        self::assertSame(0.85, $result->confidence);
    }

    #[Test]
    public function failed_factory_creates_failure_result(): void
    {
        $result = DeviceProofResult::failed('Invalid attestation');

        self::assertFalse($result->verified);
        self::assertSame(0.0, $result->confidence);
        self::assertSame('Invalid attestation', $result->reason);
        self::assertSame('', $result->deviceId);
    }

    #[Test]
    public function failed_factory_accepts_custom_confidence(): void
    {
        $result = DeviceProofResult::failed('Expired', 0.3);

        self::assertFalse($result->verified);
        self::assertSame(0.3, $result->confidence);
    }

    #[Test]
    public function constructor_allows_direct_creation(): void
    {
        $result = new DeviceProofResult(
            verified: true,
            confidence: 0.95,
            reason: 'partial match',
            deviceId: 'dev-1',
        );

        self::assertTrue($result->verified);
        self::assertSame(0.95, $result->confidence);
        self::assertSame('partial match', $result->reason);
        self::assertSame('dev-1', $result->deviceId);
    }
}
