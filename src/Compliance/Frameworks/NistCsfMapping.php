<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Frameworks;

use NoDiscard;
use Pulsar\Api\Internal;
use Pulsar\Compliance\ComplianceFramework;
use Pulsar\Compliance\Control\ControlDeclaration;
use Pulsar\Compliance\Control\ControlSubject;
use Pulsar\Compliance\Probe\AccessRestrictionProbe;
use Pulsar\Compliance\Probe\AssetInventoryProbe;
use Pulsar\Compliance\Probe\ContinuousMonitoringProbe;
use Pulsar\Compliance\Probe\DataProtectionAtRestProbe;
use Pulsar\Compliance\Probe\GovernanceProfileProbe;
use Pulsar\Compliance\Probe\IncidentResponseProbe;
use Pulsar\Compliance\Probe\PlatformHardeningProbe;
use Pulsar\Compliance\Probe\RecoveryCapabilityProbe;
use Pulsar\Compliance\Probe\RiskAssessmentProbe;
use Pulsar\Compliance\Probe\TamperEvidentAuditProbe;

/**
 * Declares the NIST Cybersecurity Framework 2.0 outcomes Pulsar can be assessed
 * against, across the six functions: Govern, Identify, Protect, Detect, Respond,
 * Recover.
 *
 * Ten of these eleven used to be registered as Implemented. RC.RP is the one that
 * matters: it was a Partial literal whose description named health checks, worker
 * restarts and "deployment primitives", none of which restores anything. For
 * several releases after that literal was removed there was no backup or restore
 * primitive in the framework at all — the only thing resembling one lived in the
 * CMS extension, for content — and RC.RP reported a measured gap. Forcing that
 * choice is what this design is for, and the choice that was made was to build the
 * primitive: {@see \Pulsar\Resilience\Backup\BackupServiceInterface}. RC.RP is
 * therefore satisfiable now, and satisfied only by a deployment whose own service
 * seals an archive, refuses a copy with one byte changed and gives the payload back
 * — never by the fact that the primitive exists.
 */
#[Internal(reason: 'Framework-internal control declaration; the catalog is the public surface')]
final class NistCsfMapping
{
    /**
     * @return list<ControlDeclaration>
     */
    #[NoDiscard]
    public static function declarations(): array
    {
        return [
            // --- GV: Govern ---

            ControlDeclaration::probed(
                id: 'NIST-GV.OC',
                framework: ComplianceFramework::NistCsf,
                title: 'Govern: Organizational Context (GV.OC)',
                requirement: 'The circumstances — mission, stakeholder expectations, dependencies, '
                    . 'and legal, regulatory and contractual requirements — surrounding the '
                    . 'organization\'s cybersecurity risk management decisions are understood.',
                probe: new GovernanceProfileProbe(),
                subject: ControlSubject::RiskGovernance,
            ),

            ControlDeclaration::probed(
                id: 'NIST-GV.RM',
                framework: ComplianceFramework::NistCsf,
                title: 'Govern: Risk Management Strategy (GV.RM)',
                requirement: 'The organization\'s priorities, constraints, risk tolerance and '
                    . 'appetite statements, and assumptions are established, communicated and used '
                    . 'to support operational risk decisions.',
                probe: new GovernanceProfileProbe(),
                subject: ControlSubject::RiskGovernance,
            ),

            // --- ID: Identify ---

            ControlDeclaration::probed(
                id: 'NIST-ID.AM',
                framework: ComplianceFramework::NistCsf,
                title: 'Identify: Asset Management (ID.AM)',
                requirement: 'Assets — data, hardware, software, systems, facilities, services and '
                    . 'people — that enable the organization to achieve business purposes are '
                    . 'identified and managed consistent with their relative importance.',
                probe: new AssetInventoryProbe(),
                subject: ControlSubject::RouteInventory,
            ),

            ControlDeclaration::probed(
                id: 'NIST-ID.RA',
                framework: ComplianceFramework::NistCsf,
                title: 'Identify: Risk Assessment (ID.RA)',
                requirement: 'The cybersecurity risk to the organization, assets and individuals '
                    . 'is understood by the organization.',
                probe: new RiskAssessmentProbe(),
                subject: ControlSubject::RiskGovernance,
            ),

            // --- PR: Protect ---

            ControlDeclaration::probed(
                id: 'NIST-PR.AA',
                framework: ComplianceFramework::NistCsf,
                title: 'Protect: Identity Management, Authentication, and Access Control (PR.AA)',
                requirement: 'Access to physical and logical assets is limited to authorized users, '
                    . 'services and hardware, and is managed commensurate with the assessed risk '
                    . 'of unauthorized access.',
                probe: new AccessRestrictionProbe(),
                subject: ControlSubject::AccessControl,
            ),

            ControlDeclaration::probed(
                id: 'NIST-PR.DS',
                framework: ComplianceFramework::NistCsf,
                title: 'Protect: Data Security (PR.DS)',
                requirement: 'Data are managed consistent with the organization\'s risk strategy to '
                    . 'protect the confidentiality, integrity and availability of information.',
                probe: new DataProtectionAtRestProbe(),
                subject: ControlSubject::ConfidentialInformation,
            ),

            ControlDeclaration::probed(
                id: 'NIST-PR.PS',
                framework: ComplianceFramework::NistCsf,
                title: 'Protect: Platform Security (PR.PS)',
                requirement: 'The hardware, software and services of physical and virtual platforms '
                    . 'are managed consistent with the organization\'s risk strategy to protect '
                    . 'their confidentiality, integrity and availability.',
                probe: new PlatformHardeningProbe(),
                subject: ControlSubject::DeploymentConfiguration,
            ),

            // --- DE: Detect ---

            ControlDeclaration::probed(
                id: 'NIST-DE.CM',
                framework: ComplianceFramework::NistCsf,
                title: 'Detect: Continuous Monitoring (DE.CM)',
                requirement: 'Assets are monitored to find anomalies, indicators of compromise and '
                    . 'other potentially adverse events.',
                probe: new ContinuousMonitoringProbe(),
                subject: ControlSubject::OperationalMonitoring,
            ),

            ControlDeclaration::probed(
                id: 'NIST-DE.AE',
                framework: ComplianceFramework::NistCsf,
                title: 'Detect: Adverse Event Analysis (DE.AE)',
                requirement: 'Anomalies, indicators of compromise and other potentially adverse '
                    . 'events are analyzed to characterize the events and detect cybersecurity '
                    . 'incidents.',
                probe: new TamperEvidentAuditProbe(),
                subject: ControlSubject::AuditTrail,
            ),

            // --- RS: Respond ---

            ControlDeclaration::probed(
                id: 'NIST-RS.MA',
                framework: ComplianceFramework::NistCsf,
                title: 'Respond: Incident Management (RS.MA)',
                requirement: 'Responses to detected cybersecurity incidents are managed.',
                probe: new IncidentResponseProbe(),
                subject: ControlSubject::IncidentResponse,
            ),

            // --- RC: Recover ---

            // Deliberately probed rather than handed to the operator. Recovery is a
            // capability the software either has or has not, and no deployment can
            // assert that recovery does not apply to it, so there is no honest way to
            // scope the control out. What decides it is a round trip against this
            // deployment's own backup service, not the existence of the class that
            // implements one -- see RecoveryCapabilityProbe.
            ControlDeclaration::probed(
                id: 'NIST-RC.RP',
                framework: ComplianceFramework::NistCsf,
                title: 'Recover: Incident Recovery Plan Execution (RC.RP)',
                requirement: 'Restoration activities are performed to ensure operational '
                    . 'availability of systems and services affected by cybersecurity incidents.',
                probe: new RecoveryCapabilityProbe(),
                subject: ControlSubject::BusinessContinuity,
            ),
        ];
    }
}
