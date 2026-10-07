<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\KeyLifecycle;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\KeyLifecycle\CertificateInfo;
use Pulsar\Security\KeyLifecycle\CertificateType;

#[CoversClass(CertificateInfo::class)]
final class CertificateInfoTest extends TestCase
{
    private function createCert(string $notAfter = '+30 days', string $notBefore = '-30 days'): CertificateInfo
    {
        return new CertificateInfo(
            identifier: 'cert-1',
            subject: 'CN=example.com',
            issuer: 'CN=CA',
            notBefore: new DateTimeImmutable($notBefore),
            notAfter: new DateTimeImmutable($notAfter),
            serialNumber: 'AABB0011',
            type: CertificateType::Tls,
        );
    }

    public function testIsExpiredReturnsFalseForValidCert(): void
    {
        $cert = $this->createCert('+30 days');

        self::assertFalse($cert->isExpired());
    }

    public function testIsExpiredReturnsTrueForExpiredCert(): void
    {
        $cert = $this->createCert('-1 day');

        self::assertTrue($cert->isExpired());
    }

    public function testIsExpiredAcceptsCustomNow(): void
    {
        $cert = $this->createCert('2026-06-01');
        $future = new DateTimeImmutable('2026-07-01');

        self::assertTrue($cert->isExpired($future));
    }

    public function testDaysUntilExpiryPositive(): void
    {
        $cert = $this->createCert('+15 days');

        $days = $cert->daysUntilExpiry();

        self::assertGreaterThanOrEqual(14, $days);
        self::assertLessThanOrEqual(15, $days);
    }

    public function testDaysUntilExpiryNegativeWhenExpired(): void
    {
        $cert = $this->createCert('-10 days');

        $days = $cert->daysUntilExpiry();

        self::assertLessThan(0, $days);
    }

    public function testExpiresWithinDaysTrue(): void
    {
        $cert = $this->createCert('+5 days');

        self::assertTrue($cert->expiresWithinDays(7));
    }

    public function testExpiresWithinDaysFalse(): void
    {
        $cert = $this->createCert('+60 days');

        self::assertFalse($cert->expiresWithinDays(7));
    }

    public function testExpiresWithinDaysCustomNow(): void
    {
        $cert = $this->createCert('2026-04-10');
        $now = new DateTimeImmutable('2026-04-05');

        self::assertTrue($cert->expiresWithinDays(7, $now));
    }

    public function testToArrayContainsAllFields(): void
    {
        $cert = new CertificateInfo(
            identifier: 'cert-abc',
            subject: 'CN=api.example.com',
            issuer: 'CN=Intermediate CA',
            notBefore: new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            notAfter: new DateTimeImmutable('2027-01-01T00:00:00+00:00'),
            serialNumber: 'FF00FF',
            type: CertificateType::Signing,
        );

        $array = $cert->toArray();

        self::assertSame('cert-abc', $array['identifier']);
        self::assertSame('CN=api.example.com', $array['subject']);
        self::assertSame('CN=Intermediate CA', $array['issuer']);
        self::assertSame('FF00FF', $array['serial_number']);
        self::assertSame('signing', $array['type']);
        self::assertArrayHasKey('not_before', $array);
        self::assertArrayHasKey('not_after', $array);
    }
}
