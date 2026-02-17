<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Verification;

use Pulsar\Api\Api;
use Pulsar\Compliance\ComplianceFramework;

use function in_array;

/**
 * Detects cross-framework compliance conflicts and provides resolutions.
 *
 * Certain regulatory frameworks have requirements that appear contradictory.
 * This detector identifies these conflicts and provides expert resolutions
 * based on legal interpretation and industry practice.
 */
#[Api(since: '1.0.0')]
final readonly class ConflictDetector
{
    /**
     * Analyze enabled frameworks for known conflicts.
     *
     * @param list<ComplianceFramework> $frameworks
     *
     * @return list<ConflictReport>
     */
    public function detect(array $frameworks): array
    {
        $conflicts = [];

        $has = static fn(ComplianceFramework $f): bool => in_array($f, $frameworks, true);

        // GDPR erasure vs PCI-DSS audit retention
        if ($has(ComplianceFramework::Gdpr) && $has(ComplianceFramework::PciDss)) {
            $conflicts[] = new ConflictReport(
                frameworkA: ComplianceFramework::Gdpr,
                frameworkB: ComplianceFramework::PciDss,
                requirementA: 'Art. 17: Right to erasure (right to be forgotten)',
                requirementB: 'Req. 10.7: Retain audit trail history for at least one year',
                description: 'GDPR grants data subjects the right to erasure, but PCI-DSS requires '
                    . 'audit log retention for a minimum of one year.',
                resolution: 'Audit logs are exempt from GDPR erasure requests under Art. 17(3)(e) '
                    . '(legal obligation). Pseudonymize personal data within audit entries after '
                    . 'the GDPR storage limitation period while retaining the audit record.',
                resolutionSteps: [
                    'Implement pseudonymization for personal data fields in audit entries.',
                    'Retain full audit records for PCI-DSS required period (1 year minimum).',
                    'After GDPR retention period, replace personal identifiers with pseudonyms.',
                    'Document this approach in your Data Protection Impact Assessment.',
                ],
            );
        }

        // GDPR storage limitation vs HIPAA 6-year retention
        if ($has(ComplianceFramework::Gdpr) && $has(ComplianceFramework::Hipaa)) {
            $conflicts[] = new ConflictReport(
                frameworkA: ComplianceFramework::Gdpr,
                frameworkB: ComplianceFramework::Hipaa,
                requirementA: 'Art. 5(1)(e): Storage limitation principle',
                requirementB: '45 CFR 164.530(j): 6-year retention for policies and procedures',
                description: 'GDPR requires data minimization and storage limitation, but HIPAA '
                    . 'mandates 6-year retention of ePHI-related documentation.',
                resolution: 'Apply the longer HIPAA retention period. Pseudonymize personal data '
                    . 'after the GDPR storage limitation period while retaining ePHI records. '
                    . 'GDPR Art. 6(1)(c) allows processing when required by law.',
                resolutionSteps: [
                    'Apply HIPAA 6-year retention as the governing period.',
                    'Pseudonymize EU personal data after GDPR-appropriate retention.',
                    'Retain de-identified health records for HIPAA compliance.',
                    'Document legal basis under GDPR Art. 6(1)(c) for retained data.',
                ],
            );
        }

        // GDPR erasure vs eIDAS 10-year qualified signature retention
        if ($has(ComplianceFramework::Gdpr) && $has(ComplianceFramework::Eidas)) {
            $conflicts[] = new ConflictReport(
                frameworkA: ComplianceFramework::Gdpr,
                frameworkB: ComplianceFramework::Eidas,
                requirementA: 'Art. 17: Right to erasure',
                requirementB: 'Art. 24: Qualified trust service provider record retention (10 years)',
                description: 'GDPR erasure rights conflict with eIDAS requirements for qualified '
                    . 'trust service providers to retain records for verification.',
                resolution: 'Trust service records fall under GDPR Art. 17(3)(b) (legal obligation). '
                    . 'Retain qualified signature and seal records for the eIDAS period. '
                    . 'Minimize personal data in retained records to what is strictly necessary.',
                resolutionSteps: [
                    'Retain qualified trust service records for 10 years per eIDAS.',
                    'Minimize personal data in trust service records.',
                    'Document legal basis under GDPR Art. 17(3)(b).',
                    'Provide data subjects with transparency about retention periods.',
                ],
            );
        }

        // PSD2 strong customer authentication vs GDPR data minimization
        if ($has(ComplianceFramework::Psd2) && $has(ComplianceFramework::Gdpr)) {
            $conflicts[] = new ConflictReport(
                frameworkA: ComplianceFramework::Psd2,
                frameworkB: ComplianceFramework::Gdpr,
                requirementA: 'Art. 97: Strong Customer Authentication for payments',
                requirementB: 'Art. 5(1)(c): Data minimization',
                description: 'PSD2 SCA requires collecting authentication data (biometrics, device '
                    . 'data) that may exceed GDPR data minimization requirements.',
                resolution: 'PSD2 SCA data collection is lawful under GDPR Art. 6(1)(c) (legal '
                    . 'obligation). Collect only authentication data strictly necessary for SCA. '
                    . 'Delete authentication session data promptly after verification.',
                resolutionSteps: [
                    'Collect only data strictly required for SCA (knowledge, possession, inherence).',
                    'Delete raw biometric/device data after authentication completes.',
                    'Retain only authentication outcome records, not raw authentication data.',
                    'Document GDPR legal basis and necessity assessment for SCA data.',
                ],
            );
        }

        // DORA ICT risk vs GDPR data protection by design
        if ($has(ComplianceFramework::Dora) && $has(ComplianceFramework::Gdpr)) {
            $conflicts[] = new ConflictReport(
                frameworkA: ComplianceFramework::Dora,
                frameworkB: ComplianceFramework::Gdpr,
                requirementA: 'Art. 9: ICT systems monitoring and logging',
                requirementB: 'Art. 5(1)(c): Data minimization',
                description: 'DORA requires comprehensive ICT monitoring and logging that may '
                    . 'capture personal data beyond GDPR minimization principles.',
                resolution: 'DORA monitoring is lawful under GDPR Art. 6(1)(c). Apply data '
                    . 'minimization within ICT logs by pseudonymizing user identifiers '
                    . 'where possible, and set retention periods aligned with DORA Art. 12.',
                resolutionSteps: [
                    'Pseudonymize personal identifiers in ICT monitoring logs.',
                    'Set log retention to DORA-required 5 years.',
                    'Apply access controls to limit who can access log personal data.',
                    'Document DPIA for monitoring systems under GDPR Art. 35.',
                ],
            );
        }

        // SWIFT CSP 7-year retention vs GDPR storage limitation
        if ($has(ComplianceFramework::SwiftCsp) && $has(ComplianceFramework::Gdpr)) {
            $conflicts[] = new ConflictReport(
                frameworkA: ComplianceFramework::SwiftCsp,
                frameworkB: ComplianceFramework::Gdpr,
                requirementA: 'Control 6.4: 7-year financial record retention',
                requirementB: 'Art. 5(1)(e): Storage limitation',
                description: 'SWIFT CSP requires 7-year retention of transaction and audit records '
                    . 'which may exceed GDPR storage limitation requirements.',
                resolution: 'Financial record retention is a legal obligation under multiple '
                    . 'jurisdictions. Apply SWIFT CSP 7-year period. Pseudonymize personal '
                    . 'data within records after GDPR-appropriate retention, retaining only '
                    . 'transaction identifiers for audit trail continuity.',
                resolutionSteps: [
                    'Retain financial records for 7 years per SWIFT CSP.',
                    'Pseudonymize personal data after 3-5 years (GDPR-appropriate period).',
                    'Maintain transaction hashes for audit chain integrity.',
                    'Document legal basis per GDPR Art. 6(1)(c) and national financial law.',
                ],
            );
        }

        return $conflicts;
    }
}
