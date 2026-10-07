<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Concern;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\Concern\CcpaRequirements;
use Pulsar\Compliance\Concern\DoraRequirements;
use Pulsar\Compliance\Concern\EidasRequirements;
use Pulsar\Compliance\Concern\FrameworkRequirements;
use Pulsar\Compliance\Concern\GdprRequirements;
use Pulsar\Compliance\Concern\HasAccessControl;
use Pulsar\Compliance\Concern\HasAuditRequirements;
use Pulsar\Compliance\Concern\HipaaRequirements;
use Pulsar\Compliance\Concern\Hl7FhirRequirements;
use Pulsar\Compliance\Concern\Iso13485Requirements;
use Pulsar\Compliance\Concern\Iso27001Requirements;
use Pulsar\Compliance\Concern\Iso42001Requirements;
use Pulsar\Compliance\Concern\MdrRequirements;
use Pulsar\Compliance\Concern\Nis2Requirements;
use Pulsar\Compliance\Concern\NistCsfRequirements;
use Pulsar\Compliance\Concern\PciDssRequirements;
use Pulsar\Compliance\Concern\Psd2Requirements;
use Pulsar\Compliance\Concern\Soc2Requirements;
use Pulsar\Compliance\Concern\SwiftCspRequirements;

#[CoversClass(FrameworkRequirements::class)]
#[CoversClass(PciDssRequirements::class)]
#[CoversClass(HipaaRequirements::class)]
#[CoversClass(GdprRequirements::class)]
#[CoversClass(Soc2Requirements::class)]
#[CoversClass(Nis2Requirements::class)]
#[CoversClass(Iso27001Requirements::class)]
#[CoversClass(Psd2Requirements::class)]
#[CoversClass(EidasRequirements::class)]
#[CoversClass(Iso42001Requirements::class)]
#[CoversClass(Hl7FhirRequirements::class)]
#[CoversClass(MdrRequirements::class)]
#[CoversClass(Iso13485Requirements::class)]
#[CoversClass(DoraRequirements::class)]
#[CoversClass(CcpaRequirements::class)]
#[CoversClass(NistCsfRequirements::class)]
#[CoversClass(SwiftCspRequirements::class)]
final class FrameworkRequirementsTest extends TestCase
{
    // ── Factory method smoke tests ──────────────────────────────────

    #[Test]
    public function pciDssFactoryReturnsCorrectType(): void
    {
        self::assertInstanceOf(PciDssRequirements::class, FrameworkRequirements::pciDss());
    }

    #[Test]
    public function hipaaFactoryReturnsCorrectType(): void
    {
        self::assertInstanceOf(HipaaRequirements::class, FrameworkRequirements::hipaa());
    }

    #[Test]
    public function gdprFactoryReturnsCorrectType(): void
    {
        self::assertInstanceOf(GdprRequirements::class, FrameworkRequirements::gdpr());
    }

    #[Test]
    public function soc2FactoryReturnsCorrectType(): void
    {
        self::assertInstanceOf(Soc2Requirements::class, FrameworkRequirements::soc2());
    }

    #[Test]
    public function nis2FactoryReturnsCorrectType(): void
    {
        self::assertInstanceOf(Nis2Requirements::class, FrameworkRequirements::nis2());
    }

    #[Test]
    public function iso27001FactoryReturnsCorrectType(): void
    {
        self::assertInstanceOf(Iso27001Requirements::class, FrameworkRequirements::iso27001());
    }

    #[Test]
    public function psd2FactoryReturnsCorrectType(): void
    {
        self::assertInstanceOf(Psd2Requirements::class, FrameworkRequirements::psd2());
    }

    #[Test]
    public function eidasFactoryReturnsCorrectType(): void
    {
        self::assertInstanceOf(EidasRequirements::class, FrameworkRequirements::eidas());
    }

    #[Test]
    public function iso42001FactoryReturnsCorrectType(): void
    {
        self::assertInstanceOf(Iso42001Requirements::class, FrameworkRequirements::iso42001());
    }

    #[Test]
    public function hl7FhirFactoryReturnsCorrectType(): void
    {
        self::assertInstanceOf(Hl7FhirRequirements::class, FrameworkRequirements::hl7Fhir());
    }

    #[Test]
    public function mdrFactoryReturnsCorrectType(): void
    {
        self::assertInstanceOf(MdrRequirements::class, FrameworkRequirements::mdr());
    }

    #[Test]
    public function iso13485FactoryReturnsCorrectType(): void
    {
        self::assertInstanceOf(Iso13485Requirements::class, FrameworkRequirements::iso13485());
    }

    #[Test]
    public function doraFactoryReturnsCorrectType(): void
    {
        self::assertInstanceOf(DoraRequirements::class, FrameworkRequirements::dora());
    }

    #[Test]
    public function ccpaFactoryReturnsCorrectType(): void
    {
        self::assertInstanceOf(CcpaRequirements::class, FrameworkRequirements::ccpa());
    }

    #[Test]
    public function nistCsfFactoryReturnsCorrectType(): void
    {
        self::assertInstanceOf(NistCsfRequirements::class, FrameworkRequirements::nistCsf());
    }

    #[Test]
    public function swiftCspFactoryReturnsCorrectType(): void
    {
        self::assertInstanceOf(SwiftCspRequirements::class, FrameworkRequirements::swiftCsp());
    }

    // ── PCI-DSS regulatory values ───────────────────────────────────

    #[Test]
    public function pciDssPasswordMinLengthIsTwelve(): void
    {
        self::assertSame(12, FrameworkRequirements::pciDss()->passwordMinLength());
    }

    #[Test]
    public function pciDssSessionIdleTimeoutIs900(): void
    {
        self::assertSame(900, FrameworkRequirements::pciDss()->sessionIdleTimeout());
    }

    #[Test]
    public function pciDssMfaRequirementIsPrivileged(): void
    {
        self::assertSame('privileged', FrameworkRequirements::pciDss()->mfaRequirement());
    }

    #[Test]
    public function pciDssAuditRetentionIs365Days(): void
    {
        self::assertSame(365, FrameworkRequirements::pciDss()->auditRetentionDays());
    }

    #[Test]
    public function pciDssRequiresTamperEvidentAudit(): void
    {
        self::assertTrue(FrameworkRequirements::pciDss()->requiresTamperEvidentAudit());
    }

    #[Test]
    public function pciDssRequiresEncryptionAtRest(): void
    {
        self::assertTrue(FrameworkRequirements::pciDss()->requiresEncryptionAtRest());
    }

    #[Test]
    public function pciDssRequiresEncryptionInTransit(): void
    {
        self::assertTrue(FrameworkRequirements::pciDss()->requiresEncryptionInTransit());
    }

    #[Test]
    public function pciDssDataRetentionIs365Days(): void
    {
        self::assertSame(365, FrameworkRequirements::pciDss()->dataRetentionDays());
    }

    #[Test]
    public function pciDssBreachNotificationHoursIsNull(): void
    {
        self::assertNull(FrameworkRequirements::pciDss()->breachNotificationHours());
    }

    // ── HIPAA regulatory values ─────────────────────────────────────

    #[Test]
    public function hipaaPasswordMinLengthIsEight(): void
    {
        self::assertSame(8, FrameworkRequirements::hipaa()->passwordMinLength());
    }

    #[Test]
    public function hipaaSessionIdleTimeoutIsNull(): void
    {
        self::assertNull(FrameworkRequirements::hipaa()->sessionIdleTimeout());
    }

    #[Test]
    public function hipaaMfaRequirementIsSensitiveData(): void
    {
        self::assertSame('sensitive-data', FrameworkRequirements::hipaa()->mfaRequirement());
    }

    #[Test]
    public function hipaaAuditRetentionIs2190Days(): void
    {
        self::assertSame(2190, FrameworkRequirements::hipaa()->auditRetentionDays());
    }

    #[Test]
    public function hipaaRequiresTamperEvidentAudit(): void
    {
        self::assertTrue(FrameworkRequirements::hipaa()->requiresTamperEvidentAudit());
    }

    #[Test]
    public function hipaaRequiresEncryptionAtRest(): void
    {
        self::assertTrue(FrameworkRequirements::hipaa()->requiresEncryptionAtRest());
    }

    #[Test]
    public function hipaaRequiresEncryptionInTransit(): void
    {
        self::assertTrue(FrameworkRequirements::hipaa()->requiresEncryptionInTransit());
    }

    #[Test]
    public function hipaaDataRetentionIs2190Days(): void
    {
        self::assertSame(2190, FrameworkRequirements::hipaa()->dataRetentionDays());
    }

    #[Test]
    public function hipaaBreachNotificationIs1440Hours(): void
    {
        self::assertSame(1440, FrameworkRequirements::hipaa()->breachNotificationHours());
    }

    #[Test]
    public function hipaaRequiresIndividualNotification(): void
    {
        self::assertTrue(FrameworkRequirements::hipaa()->requiresIndividualNotification());
    }

    #[Test]
    public function hipaaRequiresBreachRegister(): void
    {
        self::assertTrue(FrameworkRequirements::hipaa()->requiresBreachRegister());
    }

    // ── GDPR regulatory values ──────────────────────────────────────

    #[Test]
    public function gdprAuditRetentionIs365Days(): void
    {
        self::assertSame(365, FrameworkRequirements::gdpr()->auditRetentionDays());
    }

    #[Test]
    public function gdprDoesNotRequireTamperEvidentAudit(): void
    {
        self::assertFalse(FrameworkRequirements::gdpr()->requiresTamperEvidentAudit());
    }

    #[Test]
    public function gdprRequiresEncryptionAtRest(): void
    {
        self::assertTrue(FrameworkRequirements::gdpr()->requiresEncryptionAtRest());
    }

    #[Test]
    public function gdprRequiresEncryptionInTransit(): void
    {
        self::assertTrue(FrameworkRequirements::gdpr()->requiresEncryptionInTransit());
    }

    #[Test]
    public function gdprDataRetentionIs365Days(): void
    {
        self::assertSame(365, FrameworkRequirements::gdpr()->dataRetentionDays());
    }

    #[Test]
    public function gdprBreachNotificationIs72Hours(): void
    {
        self::assertSame(72, FrameworkRequirements::gdpr()->breachNotificationHours());
    }

    #[Test]
    public function gdprRequiresIndividualNotification(): void
    {
        self::assertTrue(FrameworkRequirements::gdpr()->requiresIndividualNotification());
    }

    #[Test]
    public function gdprRequiresBreachRegister(): void
    {
        self::assertTrue(FrameworkRequirements::gdpr()->requiresBreachRegister());
    }

    #[Test]
    public function gdprRequiresExplicitConsent(): void
    {
        self::assertTrue(FrameworkRequirements::gdpr()->requiresExplicitConsent());
    }

    #[Test]
    public function gdprRequiresConsentWithdrawal(): void
    {
        self::assertTrue(FrameworkRequirements::gdpr()->requiresConsentWithdrawal());
    }

    // ── SOC 2 regulatory values ─────────────────────────────────────

    #[Test]
    public function soc2PasswordMinLengthIsEight(): void
    {
        self::assertSame(8, FrameworkRequirements::soc2()->passwordMinLength());
    }

    #[Test]
    public function soc2SessionIdleTimeoutIsNull(): void
    {
        self::assertNull(FrameworkRequirements::soc2()->sessionIdleTimeout());
    }

    #[Test]
    public function soc2MfaRequirementIsPrivileged(): void
    {
        self::assertSame('privileged', FrameworkRequirements::soc2()->mfaRequirement());
    }

    #[Test]
    public function soc2AuditRetentionIs365Days(): void
    {
        self::assertSame(365, FrameworkRequirements::soc2()->auditRetentionDays());
    }

    #[Test]
    public function soc2DoesNotRequireTamperEvidentAudit(): void
    {
        self::assertFalse(FrameworkRequirements::soc2()->requiresTamperEvidentAudit());
    }

    #[Test]
    public function soc2DataRetentionIs365Days(): void
    {
        self::assertSame(365, FrameworkRequirements::soc2()->dataRetentionDays());
    }

    #[Test]
    public function soc2BreachNotificationHoursIsNull(): void
    {
        self::assertNull(FrameworkRequirements::soc2()->breachNotificationHours());
    }

    // ── NIS2 regulatory values ──────────────────────────────────────

    #[Test]
    public function nis2PasswordMinLengthIsEight(): void
    {
        self::assertSame(8, FrameworkRequirements::nis2()->passwordMinLength());
    }

    #[Test]
    public function nis2SessionIdleTimeoutIsNull(): void
    {
        self::assertNull(FrameworkRequirements::nis2()->sessionIdleTimeout());
    }

    #[Test]
    public function nis2MfaRequirementIsPrivileged(): void
    {
        self::assertSame('privileged', FrameworkRequirements::nis2()->mfaRequirement());
    }

    #[Test]
    public function nis2AuditRetentionIs1825Days(): void
    {
        self::assertSame(1825, FrameworkRequirements::nis2()->auditRetentionDays());
    }

    #[Test]
    public function nis2RequiresTamperEvidentAudit(): void
    {
        self::assertTrue(FrameworkRequirements::nis2()->requiresTamperEvidentAudit());
    }

    #[Test]
    public function nis2RequiresEncryptionAtRest(): void
    {
        self::assertTrue(FrameworkRequirements::nis2()->requiresEncryptionAtRest());
    }

    #[Test]
    public function nis2RequiresEncryptionInTransit(): void
    {
        self::assertTrue(FrameworkRequirements::nis2()->requiresEncryptionInTransit());
    }

    #[Test]
    public function nis2DataRetentionIs1825Days(): void
    {
        self::assertSame(1825, FrameworkRequirements::nis2()->dataRetentionDays());
    }

    #[Test]
    public function nis2BreachNotificationIs24Hours(): void
    {
        self::assertSame(24, FrameworkRequirements::nis2()->breachNotificationHours());
    }

    #[Test]
    public function nis2DoesNotRequireIndividualNotification(): void
    {
        self::assertFalse(FrameworkRequirements::nis2()->requiresIndividualNotification());
    }

    #[Test]
    public function nis2RequiresBreachRegister(): void
    {
        self::assertTrue(FrameworkRequirements::nis2()->requiresBreachRegister());
    }

    // ── ISO 27001 regulatory values ─────────────────────────────────

    #[Test]
    public function iso27001PasswordMinLengthIsEight(): void
    {
        self::assertSame(8, FrameworkRequirements::iso27001()->passwordMinLength());
    }

    #[Test]
    public function iso27001SessionIdleTimeoutIsNull(): void
    {
        self::assertNull(FrameworkRequirements::iso27001()->sessionIdleTimeout());
    }

    #[Test]
    public function iso27001MfaRequirementIsPrivileged(): void
    {
        self::assertSame('privileged', FrameworkRequirements::iso27001()->mfaRequirement());
    }

    #[Test]
    public function iso27001AuditRetentionIs365Days(): void
    {
        self::assertSame(365, FrameworkRequirements::iso27001()->auditRetentionDays());
    }

    #[Test]
    public function iso27001RequiresTamperEvidentAudit(): void
    {
        self::assertTrue(FrameworkRequirements::iso27001()->requiresTamperEvidentAudit());
    }

    #[Test]
    public function iso27001RequiresEncryptionAtRest(): void
    {
        self::assertTrue(FrameworkRequirements::iso27001()->requiresEncryptionAtRest());
    }

    #[Test]
    public function iso27001RequiresEncryptionInTransit(): void
    {
        self::assertTrue(FrameworkRequirements::iso27001()->requiresEncryptionInTransit());
    }

    #[Test]
    public function iso27001DataRetentionIs365Days(): void
    {
        self::assertSame(365, FrameworkRequirements::iso27001()->dataRetentionDays());
    }

    #[Test]
    public function iso27001BreachNotificationHoursIsNull(): void
    {
        self::assertNull(FrameworkRequirements::iso27001()->breachNotificationHours());
    }

    // ── PSD2 regulatory values ──────────────────────────────────────

    #[Test]
    public function psd2PasswordMinLengthIsEight(): void
    {
        self::assertSame(8, FrameworkRequirements::psd2()->passwordMinLength());
    }

    #[Test]
    public function psd2SessionIdleTimeoutIs300(): void
    {
        self::assertSame(300, FrameworkRequirements::psd2()->sessionIdleTimeout());
    }

    #[Test]
    public function psd2MfaRequirementIsAlways(): void
    {
        self::assertSame('always', FrameworkRequirements::psd2()->mfaRequirement());
    }

    #[Test]
    public function psd2AuditRetentionIs1825Days(): void
    {
        self::assertSame(1825, FrameworkRequirements::psd2()->auditRetentionDays());
    }

    #[Test]
    public function psd2RequiresTamperEvidentAudit(): void
    {
        self::assertTrue(FrameworkRequirements::psd2()->requiresTamperEvidentAudit());
    }

    #[Test]
    public function psd2DoesNotRequireEncryptionAtRest(): void
    {
        self::assertFalse(FrameworkRequirements::psd2()->requiresEncryptionAtRest());
    }

    #[Test]
    public function psd2RequiresEncryptionInTransit(): void
    {
        self::assertTrue(FrameworkRequirements::psd2()->requiresEncryptionInTransit());
    }

    #[Test]
    public function psd2DataRetentionIs1825Days(): void
    {
        self::assertSame(1825, FrameworkRequirements::psd2()->dataRetentionDays());
    }

    #[Test]
    public function psd2BreachNotificationIs4Hours(): void
    {
        self::assertSame(4, FrameworkRequirements::psd2()->breachNotificationHours());
    }

    #[Test]
    public function psd2DoesNotRequireIndividualNotification(): void
    {
        self::assertFalse(FrameworkRequirements::psd2()->requiresIndividualNotification());
    }

    #[Test]
    public function psd2RequiresBreachRegister(): void
    {
        self::assertTrue(FrameworkRequirements::psd2()->requiresBreachRegister());
    }

    // ── eIDAS regulatory values ─────────────────────────────────────

    #[Test]
    public function eidasPasswordMinLengthIsEight(): void
    {
        self::assertSame(8, FrameworkRequirements::eidas()->passwordMinLength());
    }

    #[Test]
    public function eidasSessionIdleTimeoutIsNull(): void
    {
        self::assertNull(FrameworkRequirements::eidas()->sessionIdleTimeout());
    }

    #[Test]
    public function eidasMfaRequirementIsPrivileged(): void
    {
        self::assertSame('privileged', FrameworkRequirements::eidas()->mfaRequirement());
    }

    #[Test]
    public function eidasAuditRetentionIs3650Days(): void
    {
        self::assertSame(3650, FrameworkRequirements::eidas()->auditRetentionDays());
    }

    #[Test]
    public function eidasRequiresTamperEvidentAudit(): void
    {
        self::assertTrue(FrameworkRequirements::eidas()->requiresTamperEvidentAudit());
    }

    #[Test]
    public function eidasRequiresEncryptionAtRest(): void
    {
        self::assertTrue(FrameworkRequirements::eidas()->requiresEncryptionAtRest());
    }

    #[Test]
    public function eidasRequiresEncryptionInTransit(): void
    {
        self::assertTrue(FrameworkRequirements::eidas()->requiresEncryptionInTransit());
    }

    #[Test]
    public function eidasDataRetentionIs3650Days(): void
    {
        self::assertSame(3650, FrameworkRequirements::eidas()->dataRetentionDays());
    }

    #[Test]
    public function eidasBreachNotificationIs24Hours(): void
    {
        self::assertSame(24, FrameworkRequirements::eidas()->breachNotificationHours());
    }

    #[Test]
    public function eidasDoesNotRequireIndividualNotification(): void
    {
        self::assertFalse(FrameworkRequirements::eidas()->requiresIndividualNotification());
    }

    #[Test]
    public function eidasRequiresBreachRegister(): void
    {
        self::assertTrue(FrameworkRequirements::eidas()->requiresBreachRegister());
    }

    // ── ISO 42001 regulatory values ─────────────────────────────────

    #[Test]
    public function iso42001AuditRetentionIs1825Days(): void
    {
        self::assertSame(1825, FrameworkRequirements::iso42001()->auditRetentionDays());
    }

    #[Test]
    public function iso42001RequiresTamperEvidentAudit(): void
    {
        self::assertTrue(FrameworkRequirements::iso42001()->requiresTamperEvidentAudit());
    }

    #[Test]
    public function iso42001DataRetentionIs1825Days(): void
    {
        self::assertSame(1825, FrameworkRequirements::iso42001()->dataRetentionDays());
    }

    // ── HL7 FHIR regulatory values ──────────────────────────────────

    #[Test]
    public function hl7FhirAuditRetentionIs2190Days(): void
    {
        self::assertSame(2190, FrameworkRequirements::hl7Fhir()->auditRetentionDays());
    }

    #[Test]
    public function hl7FhirDoesNotRequireTamperEvidentAudit(): void
    {
        self::assertFalse(FrameworkRequirements::hl7Fhir()->requiresTamperEvidentAudit());
    }

    #[Test]
    public function hl7FhirDataRetentionIs2190Days(): void
    {
        self::assertSame(2190, FrameworkRequirements::hl7Fhir()->dataRetentionDays());
    }

    #[Test]
    public function hl7FhirRequiresExplicitConsent(): void
    {
        self::assertTrue(FrameworkRequirements::hl7Fhir()->requiresExplicitConsent());
    }

    #[Test]
    public function hl7FhirRequiresConsentWithdrawal(): void
    {
        self::assertTrue(FrameworkRequirements::hl7Fhir()->requiresConsentWithdrawal());
    }

    // ── MDR regulatory values ───────────────────────────────────────

    #[Test]
    public function mdrAuditRetentionIs3650Days(): void
    {
        self::assertSame(3650, FrameworkRequirements::mdr()->auditRetentionDays());
    }

    #[Test]
    public function mdrRequiresTamperEvidentAudit(): void
    {
        self::assertTrue(FrameworkRequirements::mdr()->requiresTamperEvidentAudit());
    }

    #[Test]
    public function mdrDataRetentionIs3650Days(): void
    {
        self::assertSame(3650, FrameworkRequirements::mdr()->dataRetentionDays());
    }

    // ── ISO 13485 regulatory values ─────────────────────────────────

    #[Test]
    public function iso13485AuditRetentionIs3650Days(): void
    {
        self::assertSame(3650, FrameworkRequirements::iso13485()->auditRetentionDays());
    }

    #[Test]
    public function iso13485RequiresTamperEvidentAudit(): void
    {
        self::assertTrue(FrameworkRequirements::iso13485()->requiresTamperEvidentAudit());
    }

    #[Test]
    public function iso13485DataRetentionIs3650Days(): void
    {
        self::assertSame(3650, FrameworkRequirements::iso13485()->dataRetentionDays());
    }

    // ── DORA regulatory values ──────────────────────────────────────

    #[Test]
    public function doraPasswordMinLengthIsEight(): void
    {
        self::assertSame(8, FrameworkRequirements::dora()->passwordMinLength());
    }

    #[Test]
    public function doraSessionIdleTimeoutIs300(): void
    {
        self::assertSame(300, FrameworkRequirements::dora()->sessionIdleTimeout());
    }

    #[Test]
    public function doraMfaRequirementIsPrivileged(): void
    {
        self::assertSame('privileged', FrameworkRequirements::dora()->mfaRequirement());
    }

    #[Test]
    public function doraAuditRetentionIs1825Days(): void
    {
        self::assertSame(1825, FrameworkRequirements::dora()->auditRetentionDays());
    }

    #[Test]
    public function doraRequiresTamperEvidentAudit(): void
    {
        self::assertTrue(FrameworkRequirements::dora()->requiresTamperEvidentAudit());
    }

    #[Test]
    public function doraRequiresEncryptionAtRest(): void
    {
        self::assertTrue(FrameworkRequirements::dora()->requiresEncryptionAtRest());
    }

    #[Test]
    public function doraRequiresEncryptionInTransit(): void
    {
        self::assertTrue(FrameworkRequirements::dora()->requiresEncryptionInTransit());
    }

    #[Test]
    public function doraDataRetentionIs1825Days(): void
    {
        self::assertSame(1825, FrameworkRequirements::dora()->dataRetentionDays());
    }

    #[Test]
    public function doraBreachNotificationIs4Hours(): void
    {
        self::assertSame(4, FrameworkRequirements::dora()->breachNotificationHours());
    }

    #[Test]
    public function doraDoesNotRequireIndividualNotification(): void
    {
        self::assertFalse(FrameworkRequirements::dora()->requiresIndividualNotification());
    }

    #[Test]
    public function doraRequiresBreachRegister(): void
    {
        self::assertTrue(FrameworkRequirements::dora()->requiresBreachRegister());
    }

    // ── CCPA regulatory values ──────────────────────────────────────

    #[Test]
    public function ccpaAuditRetentionIs730Days(): void
    {
        self::assertSame(730, FrameworkRequirements::ccpa()->auditRetentionDays());
    }

    #[Test]
    public function ccpaDoesNotRequireTamperEvidentAudit(): void
    {
        self::assertFalse(FrameworkRequirements::ccpa()->requiresTamperEvidentAudit());
    }

    #[Test]
    public function ccpaDataRetentionIs730Days(): void
    {
        self::assertSame(730, FrameworkRequirements::ccpa()->dataRetentionDays());
    }

    #[Test]
    public function ccpaRequiresExplicitConsent(): void
    {
        self::assertTrue(FrameworkRequirements::ccpa()->requiresExplicitConsent());
    }

    #[Test]
    public function ccpaRequiresConsentWithdrawal(): void
    {
        self::assertTrue(FrameworkRequirements::ccpa()->requiresConsentWithdrawal());
    }

    #[Test]
    public function ccpaRequiresEncryptionAtRest(): void
    {
        self::assertTrue(FrameworkRequirements::ccpa()->requiresEncryptionAtRest());
    }

    #[Test]
    public function ccpaRequiresEncryptionInTransit(): void
    {
        self::assertTrue(FrameworkRequirements::ccpa()->requiresEncryptionInTransit());
    }

    #[Test]
    public function ccpaBreachNotificationHoursIsNull(): void
    {
        self::assertNull(FrameworkRequirements::ccpa()->breachNotificationHours());
    }

    // ── NIST CSF regulatory values ──────────────────────────────────

    #[Test]
    public function nistCsfPasswordMinLengthIsEight(): void
    {
        self::assertSame(8, FrameworkRequirements::nistCsf()->passwordMinLength());
    }

    #[Test]
    public function nistCsfSessionIdleTimeoutIsNull(): void
    {
        self::assertNull(FrameworkRequirements::nistCsf()->sessionIdleTimeout());
    }

    #[Test]
    public function nistCsfMfaRequirementIsPrivileged(): void
    {
        self::assertSame('privileged', FrameworkRequirements::nistCsf()->mfaRequirement());
    }

    #[Test]
    public function nistCsfAuditRetentionIs365Days(): void
    {
        self::assertSame(365, FrameworkRequirements::nistCsf()->auditRetentionDays());
    }

    #[Test]
    public function nistCsfRequiresTamperEvidentAudit(): void
    {
        self::assertTrue(FrameworkRequirements::nistCsf()->requiresTamperEvidentAudit());
    }

    #[Test]
    public function nistCsfRequiresEncryptionAtRest(): void
    {
        self::assertTrue(FrameworkRequirements::nistCsf()->requiresEncryptionAtRest());
    }

    #[Test]
    public function nistCsfRequiresEncryptionInTransit(): void
    {
        self::assertTrue(FrameworkRequirements::nistCsf()->requiresEncryptionInTransit());
    }

    #[Test]
    public function nistCsfDataRetentionIs365Days(): void
    {
        self::assertSame(365, FrameworkRequirements::nistCsf()->dataRetentionDays());
    }

    #[Test]
    public function nistCsfBreachNotificationHoursIsNull(): void
    {
        self::assertNull(FrameworkRequirements::nistCsf()->breachNotificationHours());
    }

    // ── SWIFT CSP regulatory values ─────────────────────────────────

    #[Test]
    public function swiftCspPasswordMinLengthIsTwelve(): void
    {
        self::assertSame(12, FrameworkRequirements::swiftCsp()->passwordMinLength());
    }

    #[Test]
    public function swiftCspSessionIdleTimeoutIs900(): void
    {
        self::assertSame(900, FrameworkRequirements::swiftCsp()->sessionIdleTimeout());
    }

    #[Test]
    public function swiftCspMfaRequirementIsAlways(): void
    {
        self::assertSame('always', FrameworkRequirements::swiftCsp()->mfaRequirement());
    }

    #[Test]
    public function swiftCspAuditRetentionIs2555Days(): void
    {
        self::assertSame(2555, FrameworkRequirements::swiftCsp()->auditRetentionDays());
    }

    #[Test]
    public function swiftCspRequiresTamperEvidentAudit(): void
    {
        self::assertTrue(FrameworkRequirements::swiftCsp()->requiresTamperEvidentAudit());
    }

    #[Test]
    public function swiftCspRequiresEncryptionAtRest(): void
    {
        self::assertTrue(FrameworkRequirements::swiftCsp()->requiresEncryptionAtRest());
    }

    #[Test]
    public function swiftCspRequiresEncryptionInTransit(): void
    {
        self::assertTrue(FrameworkRequirements::swiftCsp()->requiresEncryptionInTransit());
    }

    #[Test]
    public function swiftCspDataRetentionIs2555Days(): void
    {
        self::assertSame(2555, FrameworkRequirements::swiftCsp()->dataRetentionDays());
    }

    #[Test]
    public function swiftCspBreachNotificationHoursIsNull(): void
    {
        self::assertNull(FrameworkRequirements::swiftCsp()->breachNotificationHours());
    }

    #[Test]
    public function swiftCspDoesNotRequireIndividualNotification(): void
    {
        self::assertFalse(FrameworkRequirements::swiftCsp()->requiresIndividualNotification());
    }

    #[Test]
    public function swiftCspRequiresBreachRegister(): void
    {
        self::assertTrue(FrameworkRequirements::swiftCsp()->requiresBreachRegister());
    }

    // ── Interface contract verification ─────────────────────────────

    #[Test]
    #[DataProvider('accessControlImplementorsProvider')]
    public function accessControlImplementorsReturnValidMfaScope(HasAccessControl $req): void
    {
        self::assertContains($req->mfaRequirement(), ['always', 'privileged', 'sensitive-data', 'none']);
    }

    /**
     * @return iterable<string, array{HasAccessControl}>
     */
    public static function accessControlImplementorsProvider(): iterable
    {
        yield 'PCI-DSS' => [FrameworkRequirements::pciDss()];
        yield 'HIPAA' => [FrameworkRequirements::hipaa()];
        yield 'SOC 2' => [FrameworkRequirements::soc2()];
        yield 'NIS2' => [FrameworkRequirements::nis2()];
        yield 'ISO 27001' => [FrameworkRequirements::iso27001()];
        yield 'PSD2' => [FrameworkRequirements::psd2()];
        yield 'eIDAS' => [FrameworkRequirements::eidas()];
        yield 'DORA' => [FrameworkRequirements::dora()];
        yield 'NIST CSF' => [FrameworkRequirements::nistCsf()];
        yield 'SWIFT CSP' => [FrameworkRequirements::swiftCsp()];
    }

    #[Test]
    #[DataProvider('auditRetentionProvider')]
    public function auditRetentionIsPositive(HasAuditRequirements $req): void
    {
        self::assertGreaterThan(0, $req->auditRetentionDays());
    }

    /**
     * @return iterable<string, array{HasAuditRequirements}>
     */
    public static function auditRetentionProvider(): iterable
    {
        yield 'PCI-DSS' => [FrameworkRequirements::pciDss()];
        yield 'HIPAA' => [FrameworkRequirements::hipaa()];
        yield 'GDPR' => [FrameworkRequirements::gdpr()];
        yield 'SOC 2' => [FrameworkRequirements::soc2()];
        yield 'NIS2' => [FrameworkRequirements::nis2()];
        yield 'ISO 27001' => [FrameworkRequirements::iso27001()];
        yield 'PSD2' => [FrameworkRequirements::psd2()];
        yield 'eIDAS' => [FrameworkRequirements::eidas()];
        yield 'ISO 42001' => [FrameworkRequirements::iso42001()];
        yield 'HL7 FHIR' => [FrameworkRequirements::hl7Fhir()];
        yield 'MDR' => [FrameworkRequirements::mdr()];
        yield 'ISO 13485' => [FrameworkRequirements::iso13485()];
        yield 'DORA' => [FrameworkRequirements::dora()];
        yield 'CCPA' => [FrameworkRequirements::ccpa()];
        yield 'NIST CSF' => [FrameworkRequirements::nistCsf()];
        yield 'SWIFT CSP' => [FrameworkRequirements::swiftCsp()];
    }

    #[Test]
    #[DataProvider('passwordMinLengthProvider')]
    public function passwordMinLengthIsAtLeastEight(HasAccessControl $req): void
    {
        self::assertGreaterThanOrEqual(8, $req->passwordMinLength());
    }

    /**
     * @return iterable<string, array{HasAccessControl}>
     */
    public static function passwordMinLengthProvider(): iterable
    {
        yield 'PCI-DSS' => [FrameworkRequirements::pciDss()];
        yield 'HIPAA' => [FrameworkRequirements::hipaa()];
        yield 'SOC 2' => [FrameworkRequirements::soc2()];
        yield 'NIS2' => [FrameworkRequirements::nis2()];
        yield 'ISO 27001' => [FrameworkRequirements::iso27001()];
        yield 'PSD2' => [FrameworkRequirements::psd2()];
        yield 'eIDAS' => [FrameworkRequirements::eidas()];
        yield 'DORA' => [FrameworkRequirements::dora()];
        yield 'NIST CSF' => [FrameworkRequirements::nistCsf()];
        yield 'SWIFT CSP' => [FrameworkRequirements::swiftCsp()];
    }
}
