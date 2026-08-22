<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Concern;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\Concern\FrameworkRequirements;
use Pulsar\Compliance\Concern\HasAccessControl;
use Pulsar\Compliance\Concern\HasAuditRequirements;
use Pulsar\Compliance\Concern\HasBreachNotification;
use Pulsar\Compliance\Concern\HasConsentManagement;
use Pulsar\Compliance\Concern\HasDataRetention;
use Pulsar\Compliance\Concern\HasEncryptionRequirements;
use Pulsar\Compliance\Concern\HasIncidentReporting;

#[CoversClass(FrameworkRequirements::class)]
final class ConcernInterfaceTest extends TestCase
{
    #[Test]
    public function pciDssImplementsCorrectInterfaces(): void
    {
        $req = FrameworkRequirements::pciDss();

        self::assertInstanceOf(HasAccessControl::class, $req);
        self::assertInstanceOf(HasAuditRequirements::class, $req);
        self::assertInstanceOf(HasEncryptionRequirements::class, $req);
        self::assertInstanceOf(HasDataRetention::class, $req);
        self::assertInstanceOf(HasIncidentReporting::class, $req);
    }

    #[Test]
    public function pciDssReturnsCorrectValues(): void
    {
        $req = FrameworkRequirements::pciDss();

        self::assertSame(12, $req->passwordMinLength());
        self::assertSame(900, $req->sessionIdleTimeout());
        self::assertSame('privileged', $req->mfaRequirement());
        self::assertSame(365, $req->auditRetentionDays());
        self::assertTrue($req->requiresTamperEvidentAudit());
        self::assertTrue($req->requiresEncryptionAtRest());
        self::assertTrue($req->requiresEncryptionInTransit());
        self::assertSame(365, $req->dataRetentionDays());
        self::assertNull($req->breachNotificationHours());
    }

    #[Test]
    public function hipaaImplementsCorrectInterfaces(): void
    {
        $req = FrameworkRequirements::hipaa();

        self::assertInstanceOf(HasAccessControl::class, $req);
        self::assertInstanceOf(HasAuditRequirements::class, $req);
        self::assertInstanceOf(HasEncryptionRequirements::class, $req);
        self::assertInstanceOf(HasDataRetention::class, $req);
        self::assertInstanceOf(HasBreachNotification::class, $req);
    }

    #[Test]
    public function hipaaReturnsCorrectValues(): void
    {
        $req = FrameworkRequirements::hipaa();

        self::assertSame(8, $req->passwordMinLength());
        self::assertNull($req->sessionIdleTimeout());
        self::assertSame('sensitive-data', $req->mfaRequirement());
        self::assertSame(2190, $req->auditRetentionDays());
        self::assertTrue($req->requiresTamperEvidentAudit());
        self::assertTrue($req->requiresEncryptionAtRest());
        self::assertTrue($req->requiresEncryptionInTransit());
        self::assertSame(2190, $req->dataRetentionDays());
        self::assertSame(1440, $req->breachNotificationHours());
        self::assertTrue($req->requiresIndividualNotification());
        self::assertTrue($req->requiresBreachRegister());
    }

    #[Test]
    public function gdprImplementsConsentManagement(): void
    {
        $req = FrameworkRequirements::gdpr();

        self::assertInstanceOf(HasConsentManagement::class, $req);
        self::assertInstanceOf(HasBreachNotification::class, $req);
        self::assertTrue($req->requiresExplicitConsent());
        self::assertTrue($req->requiresConsentWithdrawal());
        self::assertSame(72, $req->breachNotificationHours());
        self::assertTrue($req->requiresIndividualNotification());
        self::assertTrue($req->requiresBreachRegister());
    }

    #[Test]
    public function soc2ReturnsCorrectValues(): void
    {
        $req = FrameworkRequirements::soc2();

        self::assertSame(8, $req->passwordMinLength());
        self::assertNull($req->sessionIdleTimeout());
        self::assertSame('privileged', $req->mfaRequirement());
        self::assertSame(365, $req->auditRetentionDays());
        self::assertFalse($req->requiresTamperEvidentAudit());
        self::assertSame(365, $req->dataRetentionDays());
        self::assertNull($req->breachNotificationHours());
    }

    #[Test]
    public function psd2ReturnsStrictSessionTimeout(): void
    {
        $req = FrameworkRequirements::psd2();

        self::assertSame(300, $req->sessionIdleTimeout());
        self::assertSame('always', $req->mfaRequirement());
        self::assertSame(4, $req->breachNotificationHours());
        self::assertFalse($req->requiresEncryptionAtRest());
        self::assertTrue($req->requiresEncryptionInTransit());
    }

    #[Test]
    public function eidasReturns10YearRetention(): void
    {
        $req = FrameworkRequirements::eidas();

        self::assertSame(3650, $req->auditRetentionDays());
        self::assertSame(3650, $req->dataRetentionDays());
        self::assertSame(24, $req->breachNotificationHours());
        self::assertTrue($req->requiresTamperEvidentAudit());
    }

    #[Test]
    public function doraReturnsFinancialRequirements(): void
    {
        $req = FrameworkRequirements::dora();

        self::assertSame(300, $req->sessionIdleTimeout());
        self::assertSame(4, $req->breachNotificationHours());
        self::assertSame(1825, $req->auditRetentionDays());
        self::assertTrue($req->requiresEncryptionAtRest());
        self::assertTrue($req->requiresTamperEvidentAudit());
        self::assertFalse($req->requiresIndividualNotification());
        self::assertTrue($req->requiresBreachRegister());
    }

    #[Test]
    public function swiftCspReturnsStrictestRequirements(): void
    {
        $req = FrameworkRequirements::swiftCsp();

        self::assertSame(12, $req->passwordMinLength());
        self::assertSame(900, $req->sessionIdleTimeout());
        self::assertSame('always', $req->mfaRequirement());
        self::assertSame(2555, $req->auditRetentionDays());
        self::assertSame(2555, $req->dataRetentionDays());
        self::assertNull($req->breachNotificationHours());
        self::assertTrue($req->requiresEncryptionAtRest());
        self::assertTrue($req->requiresTamperEvidentAudit());
    }

    #[Test]
    public function ccpaReturns2YearRetentionAndConsent(): void
    {
        $req = FrameworkRequirements::ccpa();

        self::assertSame(730, $req->auditRetentionDays());
        self::assertSame(730, $req->dataRetentionDays());
        self::assertTrue($req->requiresExplicitConsent());
        self::assertTrue($req->requiresConsentWithdrawal());
        self::assertTrue($req->requiresEncryptionAtRest());
        self::assertNull($req->breachNotificationHours());
    }

    #[Test]
    public function nistCsfReturnsGuidanceBasedRequirements(): void
    {
        $req = FrameworkRequirements::nistCsf();

        self::assertSame(8, $req->passwordMinLength());
        self::assertNull($req->sessionIdleTimeout());
        self::assertSame('privileged', $req->mfaRequirement());
        self::assertSame(365, $req->auditRetentionDays());
        self::assertTrue($req->requiresTamperEvidentAudit());
        self::assertTrue($req->requiresEncryptionAtRest());
        self::assertNull($req->breachNotificationHours());
    }

    #[Test]
    public function hl7FhirReturnsConsentRequirements(): void
    {
        $req = FrameworkRequirements::hl7Fhir();

        self::assertSame(2190, $req->auditRetentionDays());
        self::assertSame(2190, $req->dataRetentionDays());
        self::assertTrue($req->requiresExplicitConsent());
        self::assertTrue($req->requiresConsentWithdrawal());
        self::assertFalse($req->requiresTamperEvidentAudit());
    }

    #[Test]
    public function mdrReturns10YearRetention(): void
    {
        $req = FrameworkRequirements::mdr();

        self::assertSame(3650, $req->auditRetentionDays());
        self::assertSame(3650, $req->dataRetentionDays());
        self::assertTrue($req->requiresTamperEvidentAudit());
    }

    #[Test]
    public function iso13485ReturnsQualityRecordRetention(): void
    {
        $req = FrameworkRequirements::iso13485();

        self::assertSame(3650, $req->auditRetentionDays());
        self::assertSame(3650, $req->dataRetentionDays());
        self::assertTrue($req->requiresTamperEvidentAudit());
    }

    #[Test]
    public function iso42001ReturnsAiGovernanceRequirements(): void
    {
        $req = FrameworkRequirements::iso42001();

        self::assertSame(1825, $req->auditRetentionDays());
        self::assertSame(1825, $req->dataRetentionDays());
        self::assertTrue($req->requiresTamperEvidentAudit());
    }

    #[Test]
    public function nis2ReturnsDirectiveRequirements(): void
    {
        $req = FrameworkRequirements::nis2();

        self::assertSame(8, $req->passwordMinLength());
        self::assertNull($req->sessionIdleTimeout());
        self::assertSame('privileged', $req->mfaRequirement());
        self::assertSame(1825, $req->auditRetentionDays());
        self::assertSame(24, $req->breachNotificationHours());
        self::assertFalse($req->requiresIndividualNotification());
        self::assertTrue($req->requiresBreachRegister());
    }

    #[Test]
    public function iso27001ReturnsInformationSecurityRequirements(): void
    {
        $req = FrameworkRequirements::iso27001();

        self::assertSame(8, $req->passwordMinLength());
        self::assertNull($req->sessionIdleTimeout());
        self::assertSame('privileged', $req->mfaRequirement());
        self::assertSame(365, $req->auditRetentionDays());
        self::assertTrue($req->requiresTamperEvidentAudit());
        self::assertNull($req->breachNotificationHours());
    }

    /**
     * Verify that all 16 factory methods return non-null objects.
     */
    #[Test]
    #[DataProvider('allFrameworksProvider')]
    public function allFrameworkFactoriesReturnObjects(string $method): void
    {
        $result = FrameworkRequirements::$method();

        self::assertIsObject($result);
        self::assertInstanceOf(HasAuditRequirements::class, $result);
        self::assertInstanceOf(HasDataRetention::class, $result);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function allFrameworksProvider(): iterable
    {
        yield 'pciDss' => ['pciDss'];
        yield 'hipaa' => ['hipaa'];
        yield 'gdpr' => ['gdpr'];
        yield 'soc2' => ['soc2'];
        yield 'nis2' => ['nis2'];
        yield 'iso27001' => ['iso27001'];
        yield 'psd2' => ['psd2'];
        yield 'eidas' => ['eidas'];
        yield 'iso42001' => ['iso42001'];
        yield 'hl7Fhir' => ['hl7Fhir'];
        yield 'mdr' => ['mdr'];
        yield 'iso13485' => ['iso13485'];
        yield 'dora' => ['dora'];
        yield 'swiftCsp' => ['swiftCsp'];
        yield 'ccpa' => ['ccpa'];
        yield 'nistCsf' => ['nistCsf'];
    }
}
