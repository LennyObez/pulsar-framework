<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\KeyLifecycle;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\KeyLifecycle\CertificateExpiryWarning;
use Pulsar\Security\KeyLifecycle\CertificateInfo;
use Pulsar\Security\KeyLifecycle\CertificateType;
use Pulsar\Security\KeyLifecycle\CertificateWarningLevel;

#[CoversClass(CertificateExpiryWarning::class)]
final class CertificateExpiryWarningTest extends TestCase
{
    private function createWarning(
        int $daysRemaining = 7,
        CertificateWarningLevel $level = CertificateWarningLevel::Warning,
    ): CertificateExpiryWarning {
        $cert = new CertificateInfo(
            identifier: 'tls-cert',
            subject: 'CN=example.com',
            issuer: 'CN=Root CA',
            notBefore: new DateTimeImmutable('2025-01-01'),
            notAfter: new DateTimeImmutable('2026-06-01T00:00:00+00:00'),
            serialNumber: 'ABCD1234',
            type: CertificateType::Tls,
        );

        return new CertificateExpiryWarning(
            certificate: $cert,
            daysRemaining: $daysRemaining,
            level: $level,
        );
    }

    public function testConstructorAssignsProperties(): void
    {
        $warning = $this->createWarning(14, CertificateWarningLevel::Info);

        self::assertSame(14, $warning->daysRemaining);
        self::assertSame(CertificateWarningLevel::Info, $warning->level);
        self::assertSame('tls-cert', $warning->certificate->identifier);
    }

    public function testToArrayContainsExpectedKeys(): void
    {
        $warning = $this->createWarning(3, CertificateWarningLevel::Critical);

        $array = $warning->toArray();

        self::assertSame('tls-cert', $array['identifier']);
        self::assertSame('CN=example.com', $array['subject']);
        self::assertSame(3, $array['days_remaining']);
        self::assertSame('critical', $array['level']);
        self::assertArrayHasKey('expires_at', $array);
    }

    public function testExpiredLevelWarning(): void
    {
        $warning = $this->createWarning(0, CertificateWarningLevel::Expired);

        $array = $warning->toArray();

        self::assertSame('expired', $array['level']);
        self::assertSame(0, $array['days_remaining']);
    }
}
