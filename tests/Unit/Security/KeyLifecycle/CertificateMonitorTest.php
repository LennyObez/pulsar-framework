<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\KeyLifecycle;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\KeyLifecycle\CertificateInfo;
use Pulsar\Security\KeyLifecycle\CertificateMonitor;
use Pulsar\Security\KeyLifecycle\CertificateType;
use Pulsar\Security\KeyLifecycle\CertificateWarningLevel;

#[CoversClass(CertificateMonitor::class)]
final class CertificateMonitorTest extends TestCase
{
    public function testRegisterAndFind(): void
    {
        $monitor = new CertificateMonitor();
        $cert = $this->createCert('tls-main', daysUntilExpiry: 90);

        $monitor->register($cert);

        self::assertSame($cert, $monitor->find('tls-main'));
    }

    public function testUnregister(): void
    {
        $monitor = new CertificateMonitor();
        $monitor->register($this->createCert('tls-main', daysUntilExpiry: 90));
        $monitor->unregister('tls-main');

        self::assertNull($monitor->find('tls-main'));
    }

    public function testCheckReturnsEmptyForHealthyCertificates(): void
    {
        $monitor = new CertificateMonitor();
        $monitor->register($this->createCert('tls-main', daysUntilExpiry: 90));

        $warnings = $monitor->check();
        self::assertSame([], $warnings);
    }

    public function testCheckWarnsForExpiringCertificate(): void
    {
        // Custom thresholds with only warning-level entries (threshold <= 7)
        $monitor = new CertificateMonitor(warningThresholdDays: [7, 1]);
        $monitor->register($this->createCert('tls-main', daysUntilExpiry: 5));

        $warnings = $monitor->check();
        self::assertCount(1, $warnings);
        self::assertSame(CertificateWarningLevel::Warning, $warnings[0]->level);
    }

    public function testCheckCriticalForOneDayRemaining(): void
    {
        // Use explicit timestamps to avoid timing issues
        $now = new DateTimeImmutable('2026-03-14T12:00:00+00:00');
        $cert = new CertificateInfo(
            identifier: 'tls-crit',
            subject: 'CN=crit.example.com',
            issuer: 'CN=Test CA',
            notBefore: $now->modify('-1 year'),
            notAfter: $now->modify('+1 day'),
            serialNumber: 'CRIT1234',
            type: CertificateType::Tls,
        );

        // Single threshold=1 → Critical level (threshold <= 1)
        $monitor = new CertificateMonitor(warningThresholdDays: [1]);
        $monitor->register($cert);

        $warnings = $monitor->check($now);
        self::assertCount(1, $warnings);
        self::assertSame(CertificateWarningLevel::Critical, $warnings[0]->level);
    }

    public function testCheckExpiredCertificate(): void
    {
        $monitor = new CertificateMonitor();
        $monitor->register($this->createCert('tls-main', daysUntilExpiry: -5));

        $warnings = $monitor->check();
        self::assertCount(1, $warnings);
        self::assertSame(CertificateWarningLevel::Expired, $warnings[0]->level);
    }

    public function testHasExpiredCertificates(): void
    {
        $monitor = new CertificateMonitor();
        $monitor->register($this->createCert('good', daysUntilExpiry: 90));
        self::assertFalse($monitor->hasExpiredCertificates());

        $monitor->register($this->createCert('bad', daysUntilExpiry: -1));
        self::assertTrue($monitor->hasExpiredCertificates());
    }

    public function testIsDeploySafe(): void
    {
        $monitor = new CertificateMonitor();
        $monitor->register($this->createCert('tls', daysUntilExpiry: 30));

        self::assertTrue($monitor->isDeploySafe(minimumDaysRemaining: 7));
        self::assertFalse($monitor->isDeploySafe(minimumDaysRemaining: 60));
    }

    public function testAllReturnsList(): void
    {
        $monitor = new CertificateMonitor();
        $monitor->register($this->createCert('a', daysUntilExpiry: 30));
        $monitor->register($this->createCert('b', daysUntilExpiry: 60));

        self::assertCount(2, $monitor->all());
    }

    public function testInfoWarningLevel(): void
    {
        $monitor = new CertificateMonitor();
        $monitor->register($this->createCert('tls', daysUntilExpiry: 25));

        $warnings = $monitor->check();
        self::assertCount(1, $warnings);
        self::assertSame(CertificateWarningLevel::Info, $warnings[0]->level);
    }

    private function createCert(string $id, int $daysUntilExpiry): CertificateInfo
    {
        $now = new DateTimeImmutable();

        return new CertificateInfo(
            identifier: $id,
            subject: "CN={$id}.example.com",
            issuer: 'CN=Test CA',
            notBefore: $now->modify('-1 year'),
            notAfter: $now->modify("+{$daysUntilExpiry} days"),
            serialNumber: 'ABCD1234',
            type: CertificateType::Tls,
        );
    }
}
