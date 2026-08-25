<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Frameworks;

use NoDiscard;
use Pulsar\Api\Internal;
use Pulsar\Compliance\ComplianceFramework;
use Pulsar\Compliance\Control\ControlDeclaration;
use Pulsar\Compliance\Probe\AccessRestrictionProbe;
use Pulsar\Compliance\Probe\ContinuousMonitoringProbe;
use Pulsar\Compliance\Probe\IncidentResponseProbe;
use Pulsar\Compliance\Probe\MultiFactorAuthenticationProbe;
use Pulsar\Compliance\Probe\PlatformHardeningProbe;
use Pulsar\Compliance\Probe\SecureSessionProbe;
use Pulsar\Compliance\Probe\TamperEvidentAuditProbe;

/**
 * Declares the SWIFT Customer Security Programme controls Pulsar can be assessed
 * against.
 *
 * The CSP is largely about the environment around the messaging interface —
 * segmentation, hardening of hosts, physical security, staff vetting, patching —
 * none of which an application framework can observe. Those controls name the
 * evidence a CSP assessor asks for. The ones about the application's own data
 * flows, sessions, access control and logging are probed.
 */
#[Internal(reason: 'Framework-internal control declaration; the catalog is the public surface')]
final class SwiftCspMapping
{
    /**
     * @return list<ControlDeclaration>
     */
    #[NoDiscard]
    public static function declarations(): array
    {
        return [
            ControlDeclaration::operatorResponsibility(
                id: 'SWIFT-1.1',
                framework: ComplianceFramework::SwiftCsp,
                title: 'SWIFT Environment Protection (Mandatory)',
                requirement: 'Ensure the protection of the user local SWIFT infrastructure from '
                    . 'potentially compromised elements of the general IT environment and the '
                    . 'external environment.',
                artefact: 'The network segmentation design for the secure zone, with the firewall '
                    . 'rule set and its most recent review.',
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'SWIFT-1.2',
                framework: ComplianceFramework::SwiftCsp,
                title: 'Operating System Privileged Account Control (Mandatory)',
                requirement: 'Restrict and control the allocation and usage of administrator-level '
                    . 'operating system accounts.',
                artefact: 'The inventory of privileged operating system accounts, their approvals '
                    . 'and the periodic recertification record.',
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'SWIFT-1.3',
                framework: ComplianceFramework::SwiftCsp,
                title: 'Virtualisation and Cloud Platform Protection (Mandatory)',
                requirement: 'Secure the virtualisation or cloud platform and virtual machines hosting '
                    . 'SWIFT-related components to the same level as physical systems.',
                artefact: 'The hardening standard applied to the virtualisation or cloud platform '
                    . 'hosting SWIFT-related components, with the compliance evidence for it.',
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'SWIFT-1.4',
                framework: ComplianceFramework::SwiftCsp,
                title: 'Restriction of Internet Access (Mandatory)',
                requirement: 'Control and protect internet access from operator PCs and systems within '
                    . 'the secure zone.',
                artefact: 'The egress control configuration for the secure zone and operator PCs, '
                    . 'with evidence that general internet access is blocked.',
            ),

            ControlDeclaration::probed(
                id: 'SWIFT-1.5A',
                framework: ComplianceFramework::SwiftCsp,
                title: 'Intrusion Detection (Advisory)',
                requirement: 'Detect anomalous activity on systems or transaction records within the '
                    . 'customer connector environment.',
                probe: new ContinuousMonitoringProbe(),
            ),

            // The subject is the link between the SWIFT-related applications and
            // the operator PC. It was decided by whether THIS application's own
            // database session negotiated TLS — a different link, in a different
            // secure zone, and one a deployment can encrypt perfectly while the
            // connector traffic crosses the floor in the clear.
            ControlDeclaration::operatorResponsibility(
                id: 'SWIFT-2.1',
                framework: ComplianceFramework::SwiftCsp,
                title: 'Internal Data Flow Security (Mandatory)',
                requirement: 'Ensure the confidentiality, integrity and mutual authenticity of data '
                    . 'flows between local SWIFT-related applications and their link to the '
                    . 'operator PC.',
                artefact: 'The network diagram of the secure zone with the protection applied '
                    . 'to each internal data flow, and the evidence of mutual authentication '
                    . 'between the SWIFT-related applications and the operator PC.',
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'SWIFT-2.2',
                framework: ComplianceFramework::SwiftCsp,
                title: 'Security Updates (Mandatory)',
                requirement: 'Minimise the occurrence of known technical vulnerabilities on operator '
                    . 'PCs and within the local SWIFT infrastructure by ensuring vendor support '
                    . 'and applying security updates.',
                artefact: 'The patch management record showing the security update level of the '
                    . 'SWIFT-related components and their vendor support status.',
            ),

            ControlDeclaration::probed(
                id: 'SWIFT-2.3',
                framework: ComplianceFramework::SwiftCsp,
                title: 'System Hardening (Mandatory)',
                requirement: 'Reduce the cyber attack surface of SWIFT-related components by '
                    . 'performing system hardening.',
                probe: new PlatformHardeningProbe(),
            ),

            // Back-office to SWIFT infrastructure. Same substitution as 2.1: the
            // observed transport is the application's own database session.
            ControlDeclaration::operatorResponsibility(
                id: 'SWIFT-2.4A',
                framework: ComplianceFramework::SwiftCsp,
                title: 'Back-Office Data Flow Security (Advisory)',
                requirement: 'Ensure the confidentiality, integrity and authenticity of data flows '
                    . 'between back-office applications and the SWIFT infrastructure.',
                artefact: 'The protection applied to each back-office data flow into the SWIFT '
                    . 'infrastructure, with the authenticity mechanism named for each and the '
                    . 'evidence that it is in force.',
            ),

            // "Transmitted OR STORED outside of the secure zone" — two subjects,
            // neither of them the application's database link, and the second not a
            // transport question at all.
            ControlDeclaration::operatorResponsibility(
                id: 'SWIFT-2.5A',
                framework: ComplianceFramework::SwiftCsp,
                title: 'External Transmission Data Protection (Advisory)',
                requirement: 'Protect the confidentiality of SWIFT-related data transmitted or stored '
                    . 'outside of the secure zone.',
                artefact: 'The inventory of SWIFT-related data held or sent outside the secure '
                    . 'zone, with the protection applied to each location and each channel.',
            ),

            ControlDeclaration::probed(
                id: 'SWIFT-2.6',
                framework: ComplianceFramework::SwiftCsp,
                title: 'Operator Session Confidentiality and Integrity (Mandatory)',
                requirement: 'Protect the confidentiality and integrity of interactive operator '
                    . 'sessions that connect to the local or remote SWIFT infrastructure.',
                probe: new SecureSessionProbe(),
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'SWIFT-2.7',
                framework: ComplianceFramework::SwiftCsp,
                title: 'Vulnerability Scanning (Mandatory)',
                requirement: 'Identify known vulnerabilities within the local SWIFT environment by '
                    . 'implementing a regular vulnerability scanning process, and act upon the '
                    . 'results.',
                artefact: 'The vulnerability scanning schedule and the reports of the most recent '
                    . 'scans of the secure zone, with the remediation record.',
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'SWIFT-2.8A',
                framework: ComplianceFramework::SwiftCsp,
                title: 'Critical Activity Outsourcing (Advisory)',
                requirement: 'Ensure protection of the local SWIFT infrastructure from risks exposed '
                    . 'by the outsourcing of critical activities.',
                artefact: 'The outsourcing agreements for critical activity, with the security '
                    . 'requirements imposed and the provider assurance reports.',
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'SWIFT-2.9A',
                framework: ComplianceFramework::SwiftCsp,
                title: 'Transaction Business Controls (Advisory)',
                requirement: 'Ensure outbound transaction activity within the expected bounds of '
                    . 'normal business.',
                artefact: 'The transaction business control parameters in force, with the approval '
                    . 'record and the exception reports they produced.',
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'SWIFT-3.1',
                framework: ComplianceFramework::SwiftCsp,
                title: 'Physical Security (Mandatory)',
                requirement: 'Prevent unauthorised physical access to sensitive equipment, workplace '
                    . 'environments, hosting sites and storage.',
                artefact: 'The physical access records for the equipment, hosting sites and storage '
                    . 'holding SWIFT-related components, with the periodic access review.',
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'SWIFT-4.1',
                framework: ComplianceFramework::SwiftCsp,
                title: 'Password Policy (Mandatory)',
                requirement: 'Ensure passwords are sufficiently resistant against common password '
                    . 'attacks by implementing and enforcing an effective password policy.',
                artefact: 'The password policy in force and the evidence of its enforcement at '
                    . 'credential creation and change.',
            ),

            ControlDeclaration::probed(
                id: 'SWIFT-4.2',
                framework: ComplianceFramework::SwiftCsp,
                title: 'Multi-Factor Authentication (Mandatory)',
                requirement: 'Prevent that a compromise of a single authentication factor allows '
                    . 'access into SWIFT systems, by implementing multi-factor authentication.',
                probe: new MultiFactorAuthenticationProbe(),
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'SWIFT-4.3A',
                framework: ComplianceFramework::SwiftCsp,
                title: 'Token and Credential Management (Advisory)',
                requirement: 'Ensure proper management, tracking and use of connected and disconnected '
                    . 'hardware authentication tokens and other credentials.',
                artefact: 'The credential lifecycle records for authentication tokens and API keys: '
                    . 'issuance, rotation, revocation and destruction.',
            ),

            ControlDeclaration::probed(
                id: 'SWIFT-5.1',
                framework: ComplianceFramework::SwiftCsp,
                title: 'Logical Access Control (Mandatory)',
                requirement: 'Enforce the security principles of need-to-know access, least privilege '
                    . 'and separation of duties for operator accounts.',
                probe: new AccessRestrictionProbe(),
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'SWIFT-5.2',
                framework: ComplianceFramework::SwiftCsp,
                title: 'Token Management (Mandatory)',
                requirement: 'Ensure the proper management, tracking and use of connected hardware '
                    . 'authentication tokens.',
                artefact: 'The hardware token register, with issuance, assignment and return '
                    . 'records for each token.',
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'SWIFT-5.3A',
                framework: ComplianceFramework::SwiftCsp,
                title: 'Personnel Vetting Process (Advisory)',
                requirement: 'Ensure the trustworthiness of staff operating the local SWIFT '
                    . 'environment by performing regular staff screening.',
                artefact: 'The personnel vetting records for the staff operating the SWIFT '
                    . 'environment, at the depth local law permits.',
            ),

            // The repository of recorded passwords — where operator credentials
            // are written down or stored, and how that store is protected. It was
            // decided by whether libsodium is loaded and a master key exists, which
            // is a fact about this process and not about that repository.
            ControlDeclaration::operatorResponsibility(
                id: 'SWIFT-5.4',
                framework: ComplianceFramework::SwiftCsp,
                title: 'Physical and Logical Password Storage (Mandatory)',
                requirement: 'Protect physically and logically the repository of recorded passwords.',
                artefact: 'The location of the recorded-password repository, the physical and '
                    . 'logical controls protecting it, and the record of who may open it.',
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'SWIFT-6.1',
                framework: ComplianceFramework::SwiftCsp,
                title: 'Malware Protection (Mandatory)',
                requirement: 'Ensure that local SWIFT infrastructure is protected against malware and '
                    . 'act upon the results.',
                artefact: 'The malware protection configuration and its update status on the '
                    . 'systems within the secure zone.',
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'SWIFT-6.2',
                framework: ComplianceFramework::SwiftCsp,
                title: 'Software Integrity (Mandatory)',
                requirement: 'Ensure the software integrity of the SWIFT-related components and act '
                    . 'upon the results.',
                artefact: 'The software integrity verification records for the SWIFT-related '
                    . 'applications actually running, with their signature checks.',
            ),

            // The integrity of the messaging interface's DATABASE RECORDS, checked
            // and acted upon. It was decided by whether an audit sink resolved and
            // its HMAC chain verified: a real integrity measurement, of the audit
            // trail rather than of the records the control names.
            ControlDeclaration::operatorResponsibility(
                id: 'SWIFT-6.3',
                framework: ComplianceFramework::SwiftCsp,
                title: 'Database Integrity (Mandatory)',
                requirement: 'Ensure the integrity of the database records for the SWIFT messaging '
                    . 'interface or the customer connector and act upon the results.',
                artefact: 'The database integrity check run against the messaging interface or '
                    . 'the customer connector, its schedule, its most recent results, and the '
                    . 'record of what was done about them.',
            ),

            ControlDeclaration::probed(
                id: 'SWIFT-6.4',
                framework: ComplianceFramework::SwiftCsp,
                title: 'Logging and Monitoring (Mandatory)',
                requirement: 'Record security events and detect anomalous actions and operations '
                    . 'within the local SWIFT environment.',
                probe: new TamperEvidentAuditProbe(),
            ),

            ControlDeclaration::probed(
                id: 'SWIFT-6.5A',
                framework: ComplianceFramework::SwiftCsp,
                title: 'Intrusion Detection (Advisory)',
                requirement: 'Detect and contain anomalous network activity into and within the local '
                    . 'or remote SWIFT environment.',
                probe: new ContinuousMonitoringProbe(),
            ),

            ControlDeclaration::probed(
                id: 'SWIFT-7.1',
                framework: ComplianceFramework::SwiftCsp,
                title: 'Cyber Incident Response Planning (Mandatory)',
                requirement: 'Ensure a consistent and effective approach for the management of cyber '
                    . 'incidents.',
                probe: new IncidentResponseProbe(),
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'SWIFT-7.2',
                framework: ComplianceFramework::SwiftCsp,
                title: 'Security Training and Awareness (Mandatory)',
                requirement: 'Ensure all staff are aware of and fulfil their security responsibilities '
                    . 'by performing regular awareness activities, and maintain security '
                    . 'knowledge of staff with privileged access.',
                artefact: 'The security training curriculum and the completion records for the '
                    . 'staff operating the SWIFT environment.',
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'SWIFT-7.3A',
                framework: ComplianceFramework::SwiftCsp,
                title: 'Penetration Testing (Advisory)',
                requirement: 'Validate the operational security configuration and identify security '
                    . 'gaps by performing penetration testing.',
                artefact: 'The penetration test report for the current configuration, with the '
                    . 'remediation record for each finding.',
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'SWIFT-7.4A',
                framework: ComplianceFramework::SwiftCsp,
                title: 'Scenario-Based Risk Assessment (Advisory)',
                requirement: 'Evaluate the risk and readiness of the organisation based on plausible '
                    . 'cyber attack scenarios.',
                artefact: 'The scenario-based risk assessment, naming the scenarios considered and '
                    . 'the defensive measures each produced.',
            ),
        ];
    }
}
