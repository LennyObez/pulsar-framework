<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Verification;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Compliance\ComplianceFramework;
use Pulsar\Compliance\ComplianceProfile;
use Pulsar\Core\Version;

use function array_map;

/**
 * Generates structured pre-audit reports for compliance review.
 *
 * Maps each compliance requirement to the specific Pulsar class/config that
 * satisfies it, the verification check result, code references, and evidence
 * timestamps. Output is structured JSON suitable for rendering to PDF.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class AuditReportGenerator
{
    public function __construct(
        private ComplianceProfile $profile,
    ) {}

    /**
     * Generate a structured pre-audit report.
     *
     * @return array<string, mixed>
     */
    #[NoDiscard]
    public function generate(VerificationReport $report): array
    {
        $now = new DateTimeImmutable();

        return [
            'report_type' => 'pre_audit_compliance_report',
            'generated_at' => $now->format('Y-m-d\TH:i:sP'),
            'framework_version' => Version::full(),
            'frameworks' => array_map(
                static fn(ComplianceFramework $f): array => [
                    'id' => $f->value,
                    'name' => self::frameworkDisplayName($f),
                ],
                $this->profile->enabledFrameworks,
            ),
            'profile_constraints' => $this->profileConstraints(),
            'verification_summary' => [
                'total_checks' => $report->totalCount(),
                'passed' => $report->passCount(),
                'failed' => $report->failCount(),
                'skipped' => $report->skipCount(),
                'pass_rate' => $report->passRate(),
                'has_failures' => $report->hasFailures(),
            ],
            'check_results' => array_map(
                static fn(CheckResult $r): array => [
                    'check_id' => $r->checkId,
                    'domain' => $r->domain->value,
                    'status' => $r->status->value,
                    'message' => $r->message,
                    'evidence' => $r->evidence,
                    'remediations' => $r->remediations,
                    'verified_at' => $r->verifiedAt,
                ],
                $report->results,
            ),
            'cross_framework_conflicts' => array_map(
                static fn(ConflictReport $c): array => $c->toArray(),
                $report->conflicts,
            ),
            'configuration_regressions' => array_map(
                static fn(RegressionViolation $v): array => $v->toArray(),
                $report->regressions,
            ),
            'control_coverage_map' => $this->buildControlCoverageMap(),
            'disclaimer' => 'This report documents automated verification of technical controls. '
                . 'It does not constitute a compliance certification or audit opinion. '
                . 'Compliance requires organizational policies, procedures, and human oversight '
                . 'beyond the scope of automated verification.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function profileConstraints(): array
    {
        return [
            'password_min_length' => $this->profile->passwordMinLength,
            'session_idle_timeout' => $this->profile->sessionIdleTimeout,
            'breach_notification_hours' => $this->profile->breachNotificationHours,
            'audit_retention_days' => $this->profile->auditRetentionDays,
            'data_retention_days' => $this->profile->dataRetentionDays,
            'mfa_requirement' => $this->profile->mfaRequirement,
            'encryption_at_rest' => $this->profile->encryptionAtRest,
            'encryption_in_transit' => $this->profile->encryptionInTransit,
            'tamper_evident_audit' => $this->profile->tamperEvidentAudit,
            'explicit_consent' => $this->profile->explicitConsent,
            'consent_withdrawal' => $this->profile->consentWithdrawal,
            'individual_notification' => $this->profile->individualNotification,
            'breach_register' => $this->profile->breachRegister,
        ];
    }

    /**
     * Map Pulsar components to the compliance domains they satisfy.
     *
     * @return array<string, array{component: string, file: string, description: string}>
     */
    private function buildControlCoverageMap(): array
    {
        return [
            'encryption.at_rest' => [
                'component' => 'Pulsar\\Security\\Crypto\\Encryptor',
                'file' => 'src/Security/Crypto/Encryptor.php',
                'description' => 'AES-256-GCM encryption via libsodium/OpenSSL FIPS provider.',
            ],
            'encryption.in_transit' => [
                'component' => 'Pulsar\\Security\\Middleware\\SecurityHeadersMiddleware',
                'file' => 'src/Security/Middleware/SecurityHeadersMiddleware.php',
                'description' => 'HSTS enforcement, TLS-only transport.',
            ],
            'encryption.key_management' => [
                'component' => 'Pulsar\\Security\\Crypto\\MasterKey',
                'file' => 'src/Security/Crypto/MasterKey.php',
                'description' => 'Master key KDF with domain-separated subkey derivation.',
            ],
            'authentication.session' => [
                'component' => 'Pulsar\\Security\\Session\\SessionManager',
                'file' => 'src/Security/Session/SessionManager.php',
                'description' => 'Encrypted session management with idle timeout enforcement.',
            ],
            'authentication.csrf' => [
                'component' => 'Pulsar\\Security\\Csrf\\CsrfMiddleware',
                'file' => 'src/Security/Csrf/CsrfMiddleware.php',
                'description' => 'CSRF token validation middleware.',
            ],
            'audit.tamper_evident' => [
                'component' => 'Pulsar\\Security\\Audit\\AuditLogger',
                'file' => 'src/Security/Audit/AuditLogger.php',
                'description' => 'HMAC-chained tamper-evident audit logging.',
            ],
            'audit.chain_verification' => [
                'component' => 'Pulsar\\Security\\Audit\\AuditChainVerifier',
                'file' => 'src/Security/Audit/AuditChainVerifier.php',
                'description' => 'Audit entry HMAC and chain integrity verification.',
            ],
            'compliance.profile_resolution' => [
                'component' => 'Pulsar\\Compliance\\ComplianceProfileResolver',
                'file' => 'src/Compliance/ComplianceProfileResolver.php',
                'description' => 'Most-restrictive-wins profile resolution across frameworks.',
            ],
            'compliance.evidence_collection' => [
                'component' => 'Pulsar\\Compliance\\Evidence\\EvidenceCollector',
                'file' => 'src/Compliance/Evidence/EvidenceCollector.php',
                'description' => 'Automated compliance evidence collection and storage.',
            ],
        ];
    }

    private static function frameworkDisplayName(ComplianceFramework $framework): string
    {
        return match ($framework) {
            ComplianceFramework::Soc2 => 'SOC 2 Trust Services Criteria',
            ComplianceFramework::Hipaa => 'HIPAA Security Rule (2026 NPRM)',
            ComplianceFramework::Gdpr => 'EU General Data Protection Regulation',
            ComplianceFramework::PciDss => 'PCI-DSS v4.0.1',
            ComplianceFramework::Nis2 => 'NIS2 Directive (EU 2022/2555)',
            ComplianceFramework::Iso27001 => 'ISO/IEC 27001:2022',
            ComplianceFramework::Psd2 => 'PSD2 (Payment Services Directive 2)',
            ComplianceFramework::Eidas => 'eIDAS Regulation (EU No 910/2014)',
            ComplianceFramework::Iso42001 => 'ISO/IEC 42001:2023 (AI Management)',
            ComplianceFramework::Hl7Fhir => 'HL7 FHIR R4',
            ComplianceFramework::Mdr => 'MDR (EU 2017/745)',
            ComplianceFramework::Iso13485 => 'ISO 13485:2016',
            ComplianceFramework::Dora => 'DORA (EU 2022/2554)',
            ComplianceFramework::SwiftCsp => 'SWIFT CSP CSCF v2024',
            ComplianceFramework::Ccpa => 'California Consumer Privacy Act (CCPA)',
            ComplianceFramework::NistCsf => 'NIST Cybersecurity Framework (CSF)',
            ComplianceFramework::Dsa => 'Digital Services Act (EU 2022/2065)',
            ComplianceFramework::DataAct => 'EU Data Act (Regulation 2023/2854)',
        };
    }
}
