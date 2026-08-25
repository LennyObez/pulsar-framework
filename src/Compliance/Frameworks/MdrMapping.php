<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Frameworks;

use NoDiscard;
use Pulsar\Api\Internal;
use Pulsar\Compliance\ComplianceFramework;
use Pulsar\Compliance\Control\ControlDeclaration;
use Pulsar\Compliance\Probe\ContinuousMonitoringProbe;
use Pulsar\Compliance\Probe\IncidentResponseProbe;
use Pulsar\Compliance\Probe\TamperEvidentAuditProbe;

/**
 * Declares the EU MDR controls Pulsar can be assessed against.
 *
 * UDI assignment, classification, clinical evaluation and EUDAMED registration are
 * regulatory acts performed by the manufacturer; the declarations name the
 * submissions and files rather than claiming the framework covers them.
 */
#[Internal(reason: 'Framework-internal control declaration; the catalog is the public surface')]
final class MdrMapping
{
    /**
     * @return list<ControlDeclaration>
     */
    #[NoDiscard]
    public static function declarations(): array
    {
        return [
            ControlDeclaration::operatorResponsibility(
                id: 'MDR-UDI-001',
                framework: ComplianceFramework::Mdr,
                title: 'Unique Device Identification (Article 27)',
                requirement: 'Manufacturers shall assign a unique device identifier to their devices '
                    . 'and submit it with the required data elements to the UDI database '
                    . '(Article 27).',
                artefact: 'The UDI assignment records for each device and its packaging levels, '
                    . 'with the corresponding UDI database submissions.',
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'MDR-CLASS-001',
                framework: ComplianceFramework::Mdr,
                title: 'Device Classification (Annex VIII)',
                requirement: 'Devices shall be classified as class I, IIa, IIb or III, taking into '
                    . 'account their intended purpose and inherent risks, according to the '
                    . 'rules in Annex VIII.',
                artefact: 'The documented classification rationale for each device against the '
                    . 'Annex VIII rules, with the notified body assessment where required.',
            ),

            ControlDeclaration::probed(
                id: 'MDR-PMS-001',
                framework: ComplianceFramework::Mdr,
                title: 'Post-Market Surveillance (Articles 83-86)',
                requirement: 'Manufacturers shall plan, establish and maintain a post-market '
                    . 'surveillance system proportionate to the risk class and appropriate to '
                    . 'the type of device (Articles 83 to 86).',
                probe: new ContinuousMonitoringProbe(),
            ),

            ControlDeclaration::probed(
                id: 'MDR-VIG-001',
                framework: ComplianceFramework::Mdr,
                title: 'Vigilance Reporting (Article 87)',
                requirement: 'Manufacturers shall report serious incidents and field safety corrective '
                    . 'actions to the competent authorities within the deadlines set by the '
                    . 'Regulation (Article 87).',
                probe: new IncidentResponseProbe(),
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'MDR-CLIN-001',
                framework: ComplianceFramework::Mdr,
                title: 'Clinical Investigations (Articles 62-82)',
                requirement: 'Clinical investigations shall be designed, conducted and reported in '
                    . 'accordance with the requirements of Articles 62 to 82 and Annex XV.',
                artefact: 'The clinical evaluation report and, where applicable, the clinical '
                    . 'investigation plan, ethics committee approval and competent authority '
                    . 'notification.',
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'MDR-RISK-001',
                framework: ComplianceFramework::Mdr,
                title: 'Risk Management (per ISO 14971)',
                requirement: 'Manufacturers shall establish, implement, document and maintain a risk '
                    . 'management system as a continuous iterative process throughout the '
                    . 'device life cycle.',
                artefact: 'The risk management file required by ISO 14971, with hazard analysis, '
                    . 'risk control measures and the residual risk evaluation.',
            ),

            ControlDeclaration::probed(
                id: 'MDR-TRACE-001',
                framework: ComplianceFramework::Mdr,
                title: 'Traceability (Article 25)',
                requirement: 'Economic operators shall be able to identify to whom they supplied '
                    . 'devices and from whom they were supplied, for the period the Regulation '
                    . 'prescribes (Article 25).',
                probe: new TamperEvidentAuditProbe(),
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'MDR-EUDAMED-001',
                framework: ComplianceFramework::Mdr,
                title: 'EUDAMED Integration (Article 33)',
                requirement: 'Manufacturers shall enter and keep up to date in EUDAMED the information '
                    . 'the Regulation requires about themselves and their devices (Article 33).',
                artefact: 'The EUDAMED registration confirmations for the manufacturer, the devices '
                    . 'and the certificates.',
            ),
        ];
    }
}
