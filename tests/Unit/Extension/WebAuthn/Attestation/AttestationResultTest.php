<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\WebAuthn\Attestation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Auth\WebAuthn\Attestation\AttestationResult;
use Pulsar\Extension\Auth\WebAuthn\Attestation\AttestationTrustLevel;

#[CoversClass(AttestationResult::class)]
final class AttestationResultTest extends TestCase
{
    #[Test]
    public function constructionWithAllFields(): void
    {
        $result = new AttestationResult(
            verified: true,
            format: 'packed',
            trustLevel: AttestationTrustLevel::Basic,
            aaguid: 'fbfc3007-154e-4ecc-8c0b-6e020557d7bd',
        );

        self::assertTrue($result->verified);
        self::assertSame('packed', $result->format);
        self::assertSame(AttestationTrustLevel::Basic, $result->trustLevel);
        self::assertSame('fbfc3007-154e-4ecc-8c0b-6e020557d7bd', $result->aaguid);
    }

    #[Test]
    public function constructionWithoutAaguid(): void
    {
        $result = new AttestationResult(
            verified: true,
            format: 'none',
            trustLevel: AttestationTrustLevel::None,
        );

        self::assertNull($result->aaguid);
    }

    #[Test]
    public function unverifiedResult(): void
    {
        $result = new AttestationResult(
            verified: false,
            format: 'packed',
            trustLevel: AttestationTrustLevel::None,
        );

        self::assertFalse($result->verified);
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
    }

    #[Test]
    public function attestationCaTrustLevel(): void
    {
        $result = new AttestationResult(
            verified: true,
            format: 'android-key',
            trustLevel: AttestationTrustLevel::AttestationCa,
            aaguid: '00000000-0000-0000-0000-000000000000',
        );

        self::assertSame(AttestationTrustLevel::AttestationCa, $result->trustLevel);
    }
}
