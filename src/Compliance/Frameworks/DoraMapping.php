<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Frameworks;

use NoDiscard;
use Pulsar\Api\Internal;
use Pulsar\Compliance\ComplianceFramework;
use Pulsar\Compliance\Control\ControlDeclaration;
use Pulsar\Compliance\Probe\GovernanceProfileProbe;
use Pulsar\Compliance\Probe\IncidentResponseProbe;
use Pulsar\Compliance\Probe\RecoveryCapabilityProbe;
use Pulsar\Compliance\Probe\RiskAssessmentProbe;
use Pulsar\Compliance\Probe\TamperEvidentAuditProbe;

/**
 * Declares the DORA controls Pulsar can be assessed against.
 *
 * Resilience testing, third-party risk and information sharing are discharged by
 * the financial entity, not by the software, and now name the artefact an
 * assessor should be shown. Business continuity is probed and will report a gap
 * until a backup primitive exists.
 */
#[Internal(reason: 'Framework-internal control declaration; the catalog is the public surface')]
final class DoraMapping
{
    /**
     * @return list<ControlDeclaration>
     */
    #[NoDiscard]
    public static function declarations(): array
    {
        return [
            ControlDeclaration::probed(
                id: 'DORA-RISK-001',
                framework: ComplianceFramework::Dora,
                title: 'ICT Risk Management Framework (Articles 5-16)',
                requirement: 'Financial entities shall have an internal governance and control '
                    . 'framework that ensures effective and prudent management of ICT risk '
                    . '(Articles 5 to 16).',
                probe: new RiskAssessmentProbe(),
            ),

            ControlDeclaration::probed(
                id: 'DORA-INC-001',
                framework: ComplianceFramework::Dora,
                title: 'ICT Incident Management (Articles 17-23)',
                requirement: 'Financial entities shall define, establish and implement an ICT-related '
                    . 'incident management process to detect, manage and notify ICT-related '
                    . 'incidents (Articles 17 to 23).',
                probe: new IncidentResponseProbe(),
            ),

            ControlDeclaration::probed(
                id: 'DORA-INC-002',
                framework: ComplianceFramework::Dora,
                title: 'ICT Incident Compliance Events',
                requirement: 'Financial entities shall record all ICT-related incidents and '
                    . 'significant cyber threats, and retain the records for the period '
                    . 'required by the competent authority.',
                probe: new TamperEvidentAuditProbe(),
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'DORA-TEST-001',
                framework: ComplianceFramework::Dora,
                title: 'Digital Operational Resilience Testing (Articles 24-27)',
                requirement: 'Financial entities shall establish, maintain and review a sound and '
                    . 'comprehensive digital operational resilience testing programme (Articles '
                    . '24 to 27).',
                artefact: 'The digital operational resilience testing programme, its schedule, and '
                    . 'the report of the most recent test including any threat-led penetration '
                    . 'test.',
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'DORA-TPR-001',
                framework: ComplianceFramework::Dora,
                title: 'ICT Third-Party Risk Management (Articles 28-44)',
                requirement: 'Financial entities shall manage ICT third-party risk as an integral '
                    . 'component of ICT risk, and maintain a register of contractual '
                    . 'arrangements (Articles 28 to 44).',
                artefact: 'The register of information on contractual arrangements with ICT '
                    . 'third-party service providers, including subcontracting chains and exit '
                    . 'strategies.',
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'DORA-SHARE-001',
                framework: ComplianceFramework::Dora,
                title: 'Information Sharing (Article 45)',
                requirement: 'Financial entities may exchange cyber threat information and '
                    . 'intelligence among themselves within trusted communities (Article 45).',
                artefact: 'The record of participation in cyber threat information and intelligence '
                    . 'sharing arrangements, with the arrangements themselves.',
            ),

            ControlDeclaration::probed(
                id: 'DORA-BCM-001',
                framework: ComplianceFramework::Dora,
                title: 'Business Continuity Management (Article 11)',
                requirement: 'Financial entities shall put in place an ICT business continuity policy '
                    . 'and associated response and recovery plans, including backup and '
                    . 'restoration procedures (Article 11).',
                probe: new RecoveryCapabilityProbe(),
            ),

            ControlDeclaration::probed(
                id: 'DORA-GOV-001',
                framework: ComplianceFramework::Dora,
                title: 'ICT Governance (Article 5)',
                requirement: 'The management body of a financial entity shall define, approve, oversee '
                    . 'and be accountable for the implementation of the ICT risk management '
                    . 'framework (Article 5).',
                probe: new GovernanceProfileProbe(),
            ),
        ];
    }
}
