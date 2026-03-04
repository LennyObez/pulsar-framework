<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psd2\Tests\Unit\Domain;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Psd2\Domain\CertificateInfo;
use Pulsar\Extension\Psd2\Domain\CertificateType;

final class CertificateInfoTest extends TestCase
{
    #[Test]
    public function isValidReturnsTrueWithinValidityPeriod(): void
    {
        $cert = $this->createCertInfo(
            validFrom: new DateTimeImmutable('2025-01-01'),
            validUntil: new DateTimeImmutable('2027-01-01'),
        );

        self::assertTrue($cert->isValid(new DateTimeImmutable('2026-06-15')));
    }

    #[Test]
    public function isValidReturnsFalseAfterExpiry(): void
    {
        $cert = $this->createCertInfo(
            validFrom: new DateTimeImmutable('2025-01-01'),
            validUntil: new DateTimeImmutable('2025-12-31'),
        );

        self::assertFalse($cert->isValid(new DateTimeImmutable('2026-01-01')));
    }

    #[Test]
    public function isValidReturnsFalseBeforeValidFrom(): void
    {
        $cert = $this->createCertInfo(
            validFrom: new DateTimeImmutable('2026-01-01'),
            validUntil: new DateTimeImmutable('2027-01-01'),
        );

        self::assertFalse($cert->isValid(new DateTimeImmutable('2025-06-15')));
    }

    #[Test]
    public function hasRoleReturnsTrueForMatchingRole(): void
    {
        $cert = $this->createCertInfo(psd2Roles: ['PSP_AI', 'PSP_PI']);

        self::assertTrue($cert->hasRole('PSP_AI'));
        self::assertTrue($cert->hasRole('PSP_PI'));
    }

    #[Test]
    public function hasRoleReturnsFalseForNonExistentRole(): void
    {
        $cert = $this->createCertInfo(psd2Roles: ['PSP_AI']);

        self::assertFalse($cert->hasRole('PSP_IC'));
    }

    #[Test]
    public function toArrayContainsAllFields(): void
    {
        $cert = $this->createCertInfo();
        $array = $cert->toArray();

        self::assertArrayHasKey('type', $array);
        self::assertArrayHasKey('subject', $array);
        self::assertArrayHasKey('issuer', $array);
        self::assertArrayHasKey('serial_number', $array);
        self::assertArrayHasKey('authorization_number', $array);
        self::assertArrayHasKey('psd2_roles', $array);
        self::assertArrayHasKey('nca_name', $array);
        self::assertArrayHasKey('nca_id', $array);
        self::assertArrayHasKey('valid_from', $array);
        self::assertArrayHasKey('valid_until', $array);
        self::assertArrayHasKey('is_qualified', $array);
        self::assertSame('qwac', $array['type']);
    }

    /**
     * @param list<string> $psd2Roles
     */
    private function createCertInfo(
        ?DateTimeImmutable $validFrom = null,
        ?DateTimeImmutable $validUntil = null,
        array $psd2Roles = ['PSP_AI'],
    ): CertificateInfo {
        return new CertificateInfo(
            type: CertificateType::Qwac,
            subject: 'CN=Test Bank QWAC',
            issuer: 'CN=Test CA',
            serialNumber: 'ABC123',
            authorizationNumber: 'PSDFR-ACPR-12345',
            psd2Roles: $psd2Roles,
            ncaName: 'ACPR',
            ncaId: 'FR-ACPR',
            validFrom: $validFrom ?? new DateTimeImmutable('2025-01-01'),
            validUntil: $validUntil ?? new DateTimeImmutable('2027-01-01'),
            isQualified: true,
        );
    }
}
