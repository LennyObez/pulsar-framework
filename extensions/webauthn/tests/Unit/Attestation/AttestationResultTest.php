<?php

declare(strict_types=1);

namespace Pulsar\Extension\WebAuthn\Tests\Unit\Attestation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\WebAuthn\Attestation\AttestationResult;
use Pulsar\Extension\WebAuthn\Attestation\AttestationTrustLevel;

#[CoversClass(AttestationResult::class)]
final class AttestationResultTest extends TestCase
{
    #[Test]
    public function constructWithVerifiedResult(): void
    {
        $result = new AttestationResult(
            verified: true,
            format: 'packed',
            trustLevel: AttestationTrustLevel::Basic,
            aaguid: 'f8a011f3-8c0a-4d15-8006-17111f9edc7d',
        );

        self::assertTrue($result->verified);
        self::assertSame('packed', $result->format);
        self::assertSame(AttestationTrustLevel::Basic, $result->trustLevel);
        self::assertSame('f8a011f3-8c0a-4d15-8006-17111f9edc7d', $result->aaguid);
    }

    #[Test]
    public function constructWithUnverifiedResult(): void
    {
        $result = new AttestationResult(
            verified: false,
            format: 'none',
            trustLevel: AttestationTrustLevel::None,
        );

        self::assertFalse($result->verified);
        self::assertSame('none', $result->format);
        self::assertSame(AttestationTrustLevel::None, $result->trustLevel);
        self::assertNull($result->aaguid);
    }

    #[Test]
    public function aaguidDefaultsToNull(): void
    {
        $result = new AttestationResult(
            verified: true,
            format: 'none',
            trustLevel: AttestationTrustLevel::Self,
        );

        self::assertNull($result->aaguid);
    }

    #[Test]
    public function selfAttestationTrustLevel(): void
    {
        $result = new AttestationResult(
            verified: true,
            format: 'packed',
            trustLevel: AttestationTrustLevel::Self,
        );

        self::assertSame(AttestationTrustLevel::Self, $result->trustLevel);
        self::assertSame('self', $result->trustLevel->value);
    }
}
