<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Concern;

use Override;
use Pulsar\Api\Internal;

/**
 * Concrete compliance requirements for each supported framework.
 *
 * Each method returns a framework-specific requirements object that
 * implements the relevant concern interfaces. Values are derived from
 * official regulatory text and industry best practices.
 */
#[Internal(reason: 'Used internally by ComplianceProfileResolver')]
final class FrameworkRequirements
{
    /**
     * PCI-DSS v4.0.1 requirements.
     *
     * - Password: 12 characters minimum (Req 8.3.6)
     * - Session idle: 900s / 15 min (Req 8.2.8)
     * - MFA: CDE access (Req 8.4.2)
     * - Audit retention: 365 days / 1 year (Req 10.7.1)
     * - Data retention: 365 days (Req 3.1)
     * - Breach notification: no fixed hour deadline
     * - Encryption: at rest + in transit required
     */
    public static function pciDss(): PciDssRequirements
    {
        return new PciDssRequirements();
    }

    /**
     * HIPAA 2026 NPRM requirements.
     *
     * - Password: 8 characters minimum (NIST SP 800-63B reference)
     * - Session idle: not specified (use system default)
     * - MFA: ePHI access (2026 NPRM mandatory)
     * - Audit retention: 2190 days / 6 years (45 CFR 164.530(j))
     * - Data retention: 2190 days / 6 years
     * - Breach notification: 60 days (for ≥500 individuals; 72h for restoration)
     * - Encryption: at rest + in transit mandatory (2026 NPRM)
     */
    public static function hipaa(): HipaaRequirements
    {
        return new HipaaRequirements();
    }

    /**
     * GDPR requirements.
     *
     * - Password: no specific minimum (organizational policy)
     * - Session idle: not specified
     * - MFA: not mandated (but expected for Art 32 "appropriate measures")
     * - Audit retention: 365 days minimum (Art 5(1)(e) storage limitation)
     * - Data retention: 365 days minimum
     * - Breach notification: 72 hours (Art 33)
     * - Encryption: recommended (Art 32(1)(a))
     * - Consent: explicit required (Art 6/7)
     */
    public static function gdpr(): GdprRequirements
    {
        return new GdprRequirements();
    }

    /**
     * SOC 2 requirements.
     *
     * - Password: 8 characters minimum (CC6.1 reference)
     * - Session idle: not specified
     * - MFA: privileged access (CC6.1)
     * - Audit retention: 365 days (CC7.2)
     * - Data retention: 365 days
     * - Breach notification: no fixed deadline
     * - Encryption: recommended
     */
    public static function soc2(): Soc2Requirements
    {
        return new Soc2Requirements();
    }

    /**
     * NIS2 Directive requirements.
     *
     * - Password: no specific minimum
     * - Session idle: not specified
     * - MFA: privileged access (Art 21(i))
     * - Audit retention: 1825 days / 5 years
     * - Data retention: 1825 days
     * - Breach notification: 24 hours early warning + 72 hours full (Art 23)
     * - Encryption: required (Art 21(h))
     */
    public static function nis2(): Nis2Requirements
    {
        return new Nis2Requirements();
    }

    /**
     * ISO 27001:2022 requirements.
     *
     * - Password: 8 characters minimum (A.8.5)
     * - Session idle: not specified (organizational policy)
     * - MFA: privileged access (A.8.5)
     * - Audit retention: 365 days (A.8.15)
     * - Data retention: 365 days
     * - Breach notification: no fixed deadline
     * - Encryption: required (A.8.24)
     */
    public static function iso27001(): Iso27001Requirements
    {
        return new Iso27001Requirements();
    }

    /**
     * PSD2 requirements.
     *
     * - Password: no specific minimum
     * - Session idle: 300s / 5 min (RTS Art 4)
     * - MFA: always for payments (Art 97)
     * - Audit retention: 1825 days / 5 years
     * - Data retention: 1825 days
     * - Breach notification: 4 hours (major incident, EBA guidelines)
     * - Encryption: in transit required
     */
    public static function psd2(): Psd2Requirements
    {
        return new Psd2Requirements();
    }

    /**
     * eIDAS requirements.
     *
     * - Password: no specific minimum
     * - Session idle: not specified
     * - MFA: qualified trust services (Art 24)
     * - Audit retention: 3650 days / 10 years (qualified signature retention)
     * - Data retention: 3650 days
     * - Breach notification: 24 hours (Art 19(2))
     * - Encryption: required for trust services
     */
    public static function eidas(): EidasRequirements
    {
        return new EidasRequirements();
    }

    /**
     * ISO 42001:2023 requirements.
     *
     * - Password: no specific minimum (inherits from ISO 27001)
     * - Session idle: not specified
     * - MFA: none specified (inherits from ISO 27001)
     * - Audit retention: 1825 days / 5 years (Clause 9.2)
     * - Data retention: 1825 days
     * - Breach notification: no fixed deadline
     * - Tamper-evident audit required (Clause 9.1)
     */
    public static function iso42001(): Iso42001Requirements
    {
        return new Iso42001Requirements();
    }

    /**
     * HL7 FHIR requirements.
     *
     * FHIR is an interoperability standard, not a regulatory framework.
     * It inherits security requirements from HIPAA when used in healthcare.
     * - Consent: required for patient data
     * - Audit: FHIR AuditEvent resources
     */
    public static function hl7Fhir(): Hl7FhirRequirements
    {
        return new Hl7FhirRequirements();
    }

    /**
     * MDR (EU 2017/745) requirements.
     *
     * MDR is a product regulation, not an ICT/security framework.
     * Audit retention and data retention reflect device lifecycle requirements.
     */
    public static function mdr(): MdrRequirements
    {
        return new MdrRequirements();
    }

    /**
     * ISO 13485:2016 requirements.
     *
     * QMS standard for medical device manufacturers.
     * Audit and data retention aligned with quality record requirements.
     */
    public static function iso13485(): Iso13485Requirements
    {
        return new Iso13485Requirements();
    }

    /**
     * DORA (EU 2022/2554) requirements.
     *
     * - Password: no specific minimum (inherits from NIS2/PSD2)
     * - Session idle: 300s / 5 min (aligned with PSD2 for financial entities)
     * - MFA: privileged access (aligned with NIS2)
     * - Audit retention: 1825 days / 5 years (Art 12)
     * - Data retention: 1825 days
     * - Breach notification: 4 hours (major ICT incident, Art 19)
     * - Encryption: at rest + in transit required
     * - Tamper-evident audit: required
     */
    public static function dora(): DoraRequirements
    {
        return new DoraRequirements();
    }

    /**
     * CCPA/CPRA requirements (California Consumer Privacy Act + California Privacy Rights Act).
     *
     * - Password: no specific minimum (organizational policy)
     * - Session idle: not specified
     * - MFA: none specified
     * - Audit retention: 730 days / 2 years (right-to-know lookback period, Cal. Civ. Code §1798.130)
     * - Data retention: 730 days
     * - Breach notification: no fixed hour deadline (Cal. Civ. Code §1798.82 says "expedient")
     * - Encryption: recommended (safe harbor for encrypted data, §1798.150)
     * - Consent: opt-out model (Right to Opt-Out, §1798.120); explicit for sensitive PI (CPRA)
     */
    public static function ccpa(): CcpaRequirements
    {
        return new CcpaRequirements();
    }

    /**
     * NIST CSF 2.0 (Cybersecurity Framework) requirements.
     *
     * NIST CSF is a risk-management framework, not a prescriptive regulation.
     * It defines six functions: Govern, Identify, Protect, Detect, Respond, Recover.
     * Requirements are expressed as organizational outcomes, not fixed thresholds.
     * - Password: 8 characters minimum (reference to NIST SP 800-63B)
     * - Session idle: not specified (organizational risk decision)
     * - MFA: privileged access (PR.AA subcategory)
     * - Audit retention: 365 days (DE.CM monitoring baseline)
     * - Data retention: 365 days
     * - Breach notification: no fixed hour deadline
     * - Encryption: at rest + in transit (PR.DS subcategory)
     * - Tamper-evident audit: recommended (DE.AE subcategory)
     */
    public static function nistCsf(): NistCsfRequirements
    {
        return new NistCsfRequirements();
    }

    /**
     * SWIFT CSP (Customer Security Programme) CSCF v2024 requirements.
     *
     * - Password: 12 characters minimum (Control 4.1)
     * - Session idle: 900s / 15 min (SWIFT operator session policy)
     * - MFA: always for SWIFT infrastructure access (Control 4.2)
     * - Audit retention: 2555 days / 7 years (financial record retention)
     * - Data retention: 2555 days
     * - Breach notification: no fixed deadline (notify SWIFT via ISAC)
     * - Encryption: at rest + in transit required (Controls 2.1, 2.5A)
     * - Tamper-evident audit: required (Control 6.3, 6.4)
     */
    public static function swiftCsp(): SwiftCspRequirements
    {
        return new SwiftCspRequirements();
    }

    /**
     * Digital Services Act (EU 2022/2065) requirements.
     *
     * The DSA regulates online intermediary services with transparency,
     * content moderation, and notice-and-action obligations.
     * - Audit retention: 1825 days / 5 years (Art. 16 hosting obligations)
     * - Data retention: 1825 days
     * - Breach notification: no fixed hour deadline (Art. 18 reporting)
     * - Tamper-evident audit: required for transparency reporting (Art. 15, Art. 24, Art. 42)
     */
    public static function dsa(): DsaRequirements
    {
        return new DsaRequirements();
    }

    /**
     * EU Data Act (Regulation 2023/2854) requirements.
     *
     * The Data Act establishes rules on data access, portability,
     * interoperability, and cloud switching.
     * - Audit retention: 1825 days / 5 years (data access audit trails)
     * - Data retention: 1825 days
     * - Breach notification: no fixed hour deadline
     * - Encryption: in transit required (data sharing security)
     */
    public static function dataAct(): DataActRequirements
    {
        return new DataActRequirements();
    }
}

/**
 * @internal
 */
final readonly class PciDssRequirements implements HasAccessControl, HasAuditRequirements, HasEncryptionRequirements, HasDataRetention, HasIncidentReporting
{
    #[Override]
    public function passwordMinLength(): int
    {
        return 12;
    }

    #[Override]
    public function sessionIdleTimeout(): int
    {
        return 900;
    }

    #[Override]
    public function mfaRequirement(): string
    {
        return 'privileged';
    }

    #[Override]
    public function auditRetentionDays(): int
    {
        return 365;
    }

    #[Override]
    public function requiresTamperEvidentAudit(): bool
    {
        return true;
    }

    #[Override]
    public function requiresEncryptionAtRest(): bool
    {
        return true;
    }

    #[Override]
    public function requiresEncryptionInTransit(): bool
    {
        return true;
    }

    #[Override]
    public function dataRetentionDays(): int
    {
        return 365;
    }

    #[Override]
    public function breachNotificationHours(): ?int
    {
        return null;
    }
}

/**
 * @internal
 */
final readonly class HipaaRequirements implements HasAccessControl, HasAuditRequirements, HasEncryptionRequirements, HasDataRetention, HasBreachNotification
{
    #[Override]
    public function passwordMinLength(): int
    {
        return 8;
    }

    #[Override]
    public function sessionIdleTimeout(): ?int
    {
        return null;
    }

    #[Override]
    public function mfaRequirement(): string
    {
        return 'sensitive-data';
    }

    #[Override]
    public function auditRetentionDays(): int
    {
        return 2190;
    }

    #[Override]
    public function requiresTamperEvidentAudit(): bool
    {
        return true;
    }

    #[Override]
    public function requiresEncryptionAtRest(): bool
    {
        return true;
    }

    #[Override]
    public function requiresEncryptionInTransit(): bool
    {
        return true;
    }

    #[Override]
    public function dataRetentionDays(): int
    {
        return 2190;
    }

    #[Override]
    public function breachNotificationHours(): int
    {
        return 1440;
    }

    #[Override]
    public function requiresIndividualNotification(): bool
    {
        return true;
    }

    #[Override]
    public function requiresBreachRegister(): bool
    {
        return true;
    }
}

/**
 * @internal
 */
final readonly class GdprRequirements implements HasAuditRequirements, HasEncryptionRequirements, HasDataRetention, HasBreachNotification, HasConsentManagement
{
    #[Override]
    public function auditRetentionDays(): int
    {
        return 365;
    }

    #[Override]
    public function requiresTamperEvidentAudit(): bool
    {
        return false;
    }

    #[Override]
    public function requiresEncryptionAtRest(): bool
    {
        return true;
    }

    #[Override]
    public function requiresEncryptionInTransit(): bool
    {
        return true;
    }

    #[Override]
    public function dataRetentionDays(): int
    {
        return 365;
    }

    #[Override]
    public function breachNotificationHours(): int
    {
        return 72;
    }

    #[Override]
    public function requiresIndividualNotification(): bool
    {
        return true;
    }

    #[Override]
    public function requiresBreachRegister(): bool
    {
        return true;
    }

    #[Override]
    public function requiresExplicitConsent(): bool
    {
        return true;
    }

    #[Override]
    public function requiresConsentWithdrawal(): bool
    {
        return true;
    }
}

/**
 * @internal
 */
final readonly class Soc2Requirements implements HasAccessControl, HasAuditRequirements, HasDataRetention, HasIncidentReporting
{
    #[Override]
    public function passwordMinLength(): int
    {
        return 8;
    }

    #[Override]
    public function sessionIdleTimeout(): ?int
    {
        return null;
    }

    #[Override]
    public function mfaRequirement(): string
    {
        return 'privileged';
    }

    #[Override]
    public function auditRetentionDays(): int
    {
        return 365;
    }

    #[Override]
    public function requiresTamperEvidentAudit(): bool
    {
        return false;
    }

    #[Override]
    public function dataRetentionDays(): int
    {
        return 365;
    }

    #[Override]
    public function breachNotificationHours(): ?int
    {
        return null;
    }
}

/**
 * @internal
 */
final readonly class Nis2Requirements implements HasAccessControl, HasAuditRequirements, HasEncryptionRequirements, HasDataRetention, HasBreachNotification
{
    #[Override]
    public function passwordMinLength(): int
    {
        return 8;
    }

    #[Override]
    public function sessionIdleTimeout(): ?int
    {
        return null;
    }

    #[Override]
    public function mfaRequirement(): string
    {
        return 'privileged';
    }

    #[Override]
    public function auditRetentionDays(): int
    {
        return 1825;
    }

    #[Override]
    public function requiresTamperEvidentAudit(): bool
    {
        return true;
    }

    #[Override]
    public function requiresEncryptionAtRest(): bool
    {
        return true;
    }

    #[Override]
    public function requiresEncryptionInTransit(): bool
    {
        return true;
    }

    #[Override]
    public function dataRetentionDays(): int
    {
        return 1825;
    }

    #[Override]
    public function breachNotificationHours(): int
    {
        return 24;
    }

    #[Override]
    public function requiresIndividualNotification(): bool
    {
        return false;
    }

    #[Override]
    public function requiresBreachRegister(): bool
    {
        return true;
    }
}

/**
 * @internal
 */
final readonly class Iso27001Requirements implements HasAccessControl, HasAuditRequirements, HasEncryptionRequirements, HasDataRetention, HasIncidentReporting
{
    #[Override]
    public function passwordMinLength(): int
    {
        return 8;
    }

    #[Override]
    public function sessionIdleTimeout(): ?int
    {
        return null;
    }

    #[Override]
    public function mfaRequirement(): string
    {
        return 'privileged';
    }

    #[Override]
    public function auditRetentionDays(): int
    {
        return 365;
    }

    #[Override]
    public function requiresTamperEvidentAudit(): bool
    {
        return true;
    }

    #[Override]
    public function requiresEncryptionAtRest(): bool
    {
        return true;
    }

    #[Override]
    public function requiresEncryptionInTransit(): bool
    {
        return true;
    }

    #[Override]
    public function dataRetentionDays(): int
    {
        return 365;
    }

    #[Override]
    public function breachNotificationHours(): ?int
    {
        return null;
    }
}

/**
 * @internal
 */
final readonly class Psd2Requirements implements HasAccessControl, HasAuditRequirements, HasEncryptionRequirements, HasDataRetention, HasBreachNotification
{
    #[Override]
    public function passwordMinLength(): int
    {
        return 8;
    }

    #[Override]
    public function sessionIdleTimeout(): int
    {
        return 300;
    }

    #[Override]
    public function mfaRequirement(): string
    {
        return 'always';
    }

    #[Override]
    public function auditRetentionDays(): int
    {
        return 1825;
    }

    #[Override]
    public function requiresTamperEvidentAudit(): bool
    {
        return true;
    }

    #[Override]
    public function requiresEncryptionAtRest(): bool
    {
        return false;
    }

    #[Override]
    public function requiresEncryptionInTransit(): bool
    {
        return true;
    }

    #[Override]
    public function dataRetentionDays(): int
    {
        return 1825;
    }

    #[Override]
    public function breachNotificationHours(): int
    {
        return 4;
    }

    #[Override]
    public function requiresIndividualNotification(): bool
    {
        return false;
    }

    #[Override]
    public function requiresBreachRegister(): bool
    {
        return true;
    }
}

/**
 * @internal
 */
final readonly class EidasRequirements implements HasAccessControl, HasAuditRequirements, HasEncryptionRequirements, HasDataRetention, HasBreachNotification
{
    #[Override]
    public function passwordMinLength(): int
    {
        return 8;
    }

    #[Override]
    public function sessionIdleTimeout(): ?int
    {
        return null;
    }

    #[Override]
    public function mfaRequirement(): string
    {
        return 'privileged';
    }

    #[Override]
    public function auditRetentionDays(): int
    {
        return 3650;
    }

    #[Override]
    public function requiresTamperEvidentAudit(): bool
    {
        return true;
    }

    #[Override]
    public function requiresEncryptionAtRest(): bool
    {
        return true;
    }

    #[Override]
    public function requiresEncryptionInTransit(): bool
    {
        return true;
    }

    #[Override]
    public function dataRetentionDays(): int
    {
        return 3650;
    }

    #[Override]
    public function breachNotificationHours(): int
    {
        return 24;
    }

    #[Override]
    public function requiresIndividualNotification(): bool
    {
        return false;
    }

    #[Override]
    public function requiresBreachRegister(): bool
    {
        return true;
    }
}

/**
 * @internal
 */
final readonly class Iso42001Requirements implements HasAuditRequirements, HasDataRetention
{
    #[Override]
    public function auditRetentionDays(): int
    {
        return 1825;
    }

    #[Override]
    public function requiresTamperEvidentAudit(): bool
    {
        return true;
    }

    #[Override]
    public function dataRetentionDays(): int
    {
        return 1825;
    }
}

/**
 * @internal
 */
final readonly class Hl7FhirRequirements implements HasAuditRequirements, HasConsentManagement, HasDataRetention
{
    #[Override]
    public function auditRetentionDays(): int
    {
        return 2190;
    }

    #[Override]
    public function requiresTamperEvidentAudit(): bool
    {
        return false;
    }

    #[Override]
    public function dataRetentionDays(): int
    {
        return 2190;
    }

    #[Override]
    public function requiresExplicitConsent(): bool
    {
        return true;
    }

    #[Override]
    public function requiresConsentWithdrawal(): bool
    {
        return true;
    }
}

/**
 * @internal
 */
final readonly class MdrRequirements implements HasAuditRequirements, HasDataRetention
{
    #[Override]
    public function auditRetentionDays(): int
    {
        return 3650; // 10 years: device lifecycle documentation
    }

    #[Override]
    public function requiresTamperEvidentAudit(): bool
    {
        return true;
    }

    #[Override]
    public function dataRetentionDays(): int
    {
        return 3650;
    }
}

/**
 * @internal
 */
final readonly class Iso13485Requirements implements HasAuditRequirements, HasDataRetention
{
    #[Override]
    public function auditRetentionDays(): int
    {
        return 3650; // 10 years: quality record retention per §4.2.5
    }

    #[Override]
    public function requiresTamperEvidentAudit(): bool
    {
        return true;
    }

    #[Override]
    public function dataRetentionDays(): int
    {
        return 3650;
    }
}

/**
 * @internal
 */
final readonly class DoraRequirements implements HasAccessControl, HasAuditRequirements, HasEncryptionRequirements, HasDataRetention, HasBreachNotification
{
    #[Override]
    public function passwordMinLength(): int
    {
        return 8;
    }

    #[Override]
    public function sessionIdleTimeout(): int
    {
        return 300; // 5 min: aligned with PSD2 for financial entities
    }

    #[Override]
    public function mfaRequirement(): string
    {
        return 'privileged';
    }

    #[Override]
    public function auditRetentionDays(): int
    {
        return 1825; // 5 years
    }

    #[Override]
    public function requiresTamperEvidentAudit(): bool
    {
        return true;
    }

    #[Override]
    public function requiresEncryptionAtRest(): bool
    {
        return true;
    }

    #[Override]
    public function requiresEncryptionInTransit(): bool
    {
        return true;
    }

    #[Override]
    public function dataRetentionDays(): int
    {
        return 1825;
    }

    #[Override]
    public function breachNotificationHours(): int
    {
        return 4; // Major ICT incident: initial notification within 4 hours
    }

    #[Override]
    public function requiresIndividualNotification(): bool
    {
        return false; // DORA reports to competent authority, not individuals
    }

    #[Override]
    public function requiresBreachRegister(): bool
    {
        return true;
    }
}

/**
 * @internal
 */
final readonly class SwiftCspRequirements implements HasAccessControl, HasAuditRequirements, HasEncryptionRequirements, HasDataRetention, HasIncidentReporting, HasBreachNotification
{
    #[Override]
    public function passwordMinLength(): int
    {
        return 12; // Control 4.1: strong password policy
    }

    #[Override]
    public function sessionIdleTimeout(): int
    {
        return 900; // 15 min: SWIFT operator session policy
    }

    #[Override]
    public function mfaRequirement(): string
    {
        return 'always'; // Control 4.2: mandatory for all SWIFT access
    }

    #[Override]
    public function auditRetentionDays(): int
    {
        return 2555; // 7 years: financial record retention
    }

    #[Override]
    public function requiresTamperEvidentAudit(): bool
    {
        return true; // Controls 6.3, 6.4: database and logging integrity
    }

    #[Override]
    public function requiresEncryptionAtRest(): bool
    {
        return true; // Control 2.5A: data protection at rest
    }

    #[Override]
    public function requiresEncryptionInTransit(): bool
    {
        return true; // Control 2.1: internal data flow security
    }

    #[Override]
    public function dataRetentionDays(): int
    {
        return 2555; // 7 years
    }

    #[Override]
    public function breachNotificationHours(): ?int
    {
        return null; // No fixed hour deadline; notify SWIFT via ISAC
    }

    #[Override]
    public function requiresIndividualNotification(): bool
    {
        return false; // Reports to SWIFT, not to individuals
    }

    #[Override]
    public function requiresBreachRegister(): bool
    {
        return true; // Control 7.1: incident record keeping
    }
}

/**
 * @internal
 */
final readonly class CcpaRequirements implements HasAuditRequirements, HasDataRetention, HasConsentManagement, HasEncryptionRequirements, HasIncidentReporting
{
    #[Override]
    public function auditRetentionDays(): int
    {
        return 730; // 2 years: right-to-know lookback period
    }

    #[Override]
    public function requiresTamperEvidentAudit(): bool
    {
        return false;
    }

    #[Override]
    public function dataRetentionDays(): int
    {
        return 730;
    }

    #[Override]
    public function requiresExplicitConsent(): bool
    {
        return true; // CPRA requires explicit consent for sensitive personal information
    }

    #[Override]
    public function requiresConsentWithdrawal(): bool
    {
        return true; // Right to opt-out (§1798.120) and right to delete (§1798.105)
    }

    #[Override]
    public function requiresEncryptionAtRest(): bool
    {
        return true; // Safe harbor for encrypted data under §1798.150
    }

    #[Override]
    public function requiresEncryptionInTransit(): bool
    {
        return true;
    }

    #[Override]
    public function breachNotificationHours(): ?int
    {
        return null; // "Most expedient time possible": no fixed hour deadline
    }
}

/**
 * @internal
 */
final readonly class NistCsfRequirements implements HasAccessControl, HasAuditRequirements, HasEncryptionRequirements, HasDataRetention, HasIncidentReporting
{
    #[Override]
    public function passwordMinLength(): int
    {
        return 8; // NIST SP 800-63B reference
    }

    #[Override]
    public function sessionIdleTimeout(): ?int
    {
        return null; // Organizational risk decision
    }

    #[Override]
    public function mfaRequirement(): string
    {
        return 'privileged'; // PR.AA: identity management for privileged access
    }

    #[Override]
    public function auditRetentionDays(): int
    {
        return 365; // DE.CM: continuous monitoring baseline
    }

    #[Override]
    public function requiresTamperEvidentAudit(): bool
    {
        return true; // DE.AE: adverse event analysis requires integrity
    }

    #[Override]
    public function requiresEncryptionAtRest(): bool
    {
        return true; // PR.DS: data security at rest
    }

    #[Override]
    public function requiresEncryptionInTransit(): bool
    {
        return true; // PR.DS: data security in transit
    }

    #[Override]
    public function dataRetentionDays(): int
    {
        return 365;
    }

    #[Override]
    public function breachNotificationHours(): ?int
    {
        return null; // No fixed deadline: risk-based approach
    }
}

/**
 * @internal
 */
final readonly class DsaRequirements implements HasAuditRequirements, HasDataRetention
{
    #[Override]
    public function auditRetentionDays(): int
    {
        return 1825; // 5 years: content moderation and transparency records
    }

    #[Override]
    public function requiresTamperEvidentAudit(): bool
    {
        return true; // Art. 15, Art. 24, Art. 42: transparency reporting integrity
    }

    #[Override]
    public function dataRetentionDays(): int
    {
        return 1825;
    }
}

/**
 * @internal
 */
final readonly class DataActRequirements implements HasAuditRequirements, HasDataRetention, HasEncryptionRequirements
{
    #[Override]
    public function auditRetentionDays(): int
    {
        return 1825; // 5 years: data access audit trails
    }

    #[Override]
    public function requiresTamperEvidentAudit(): bool
    {
        return false;
    }

    #[Override]
    public function dataRetentionDays(): int
    {
        return 1825;
    }

    #[Override]
    public function requiresEncryptionAtRest(): bool
    {
        return false;
    }

    #[Override]
    public function requiresEncryptionInTransit(): bool
    {
        return true; // Data sharing security requirements
    }
}
