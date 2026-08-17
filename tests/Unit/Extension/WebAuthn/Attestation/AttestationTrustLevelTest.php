<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\WebAuthn\Attestation;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Auth\WebAuthn\Attestation\AttestationTrustLevel;

#[CoversNothing]
final class AttestationTrustLevelTest extends TestCase
{
    #[Test]
    public function noneHasCorrectValue(): void
    {
        self::assertSame('none', AttestationTrustLevel::None->value);
    }

    #[Test]
    public function selfHasCorrectValue(): void
    {
        self::assertSame('self', AttestationTrustLevel::Self->value);
    }

    #[Test]
    public function basicHasCorrectValue(): void
    {
        self::assertSame('basic', AttestationTrustLevel::Basic->value);
    }

    #[Test]
    public function attestationCaHasCorrectValue(): void
    {
        self::assertSame('attestation_ca', AttestationTrustLevel::AttestationCa->value);
    }

    #[Test]
    public function allCasesExist(): void
    {
        $cases = AttestationTrustLevel::cases();

        self::assertCount(4, $cases);
    }

    #[Test]
    public function canBeCreatedFromValue(): void
    {
        $level = AttestationTrustLevel::from('basic');

        self::assertSame(AttestationTrustLevel::Basic, $level);
    }

    #[Test]
    public function tryFromReturnsNullForInvalidValue(): void
    {
        $level = AttestationTrustLevel::tryFrom('invalid');

        self::assertNull($level);
    }
}
