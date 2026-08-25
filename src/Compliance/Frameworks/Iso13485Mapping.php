<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Frameworks;

use NoDiscard;
use Pulsar\Api\Internal;
use Pulsar\Compliance\ComplianceFramework;
use Pulsar\Compliance\Control\ControlDeclaration;
use Pulsar\Compliance\Probe\ContinuousMonitoringProbe;
use Pulsar\Compliance\Probe\TamperEvidentAuditProbe;

/**
 * Declares the ISO 13485 quality-management controls Pulsar can be assessed against.
 *
 * A quality management system is a body of records and procedures held by the
 * manufacturer. Almost all of it is outside any software's reach, and the
 * declarations say so, naming the file an auditor asks for.
 */
#[Internal(reason: 'Framework-internal control declaration; the catalog is the public surface')]
final class Iso13485Mapping
{
    /**
     * @return list<ControlDeclaration>
     */
    #[NoDiscard]
    public static function declarations(): array
    {
        return [
            ControlDeclaration::operatorResponsibility(
                id: 'ISO13485-DC-001',
                framework: ComplianceFramework::Iso13485,
                title: 'Design Controls (Section 7.3)',
                requirement: 'The organization shall document design and development procedures and '
                    . 'maintain records of design and development planning, inputs, outputs, '
                    . 'review, verification, validation and transfer (Section 7.3).',
                artefact: 'The design history file for the device, covering design inputs, outputs, '
                    . 'review, verification, validation and transfer, with the review records.',
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'ISO13485-CAPA-001',
                framework: ComplianceFramework::Iso13485,
                title: 'CAPA: Corrective Action (Section 8.5.2)',
                requirement: 'The organization shall take action to eliminate the cause of '
                    . 'nonconformities in order to prevent recurrence, and shall document the '
                    . 'results of the action taken (Section 8.5.2).',
                artefact: 'The corrective action records, with root cause analysis, the actions '
                    . 'taken, and the verification that they were effective.',
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'ISO13485-CAPA-002',
                framework: ComplianceFramework::Iso13485,
                title: 'CAPA: Preventive Action (Section 8.5.3)',
                requirement: 'The organization shall determine action to eliminate the causes of '
                    . 'potential nonconformities in order to prevent their occurrence (Section '
                    . '8.5.3).',
                artefact: 'The preventive action records, with the assessment of potential '
                    . 'nonconformities, the actions taken, and the verification that they were '
                    . 'effective.',
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'ISO13485-RISK-001',
                framework: ComplianceFramework::Iso13485,
                title: 'Risk Management (Section 7.1 + ISO 14971)',
                requirement: 'The organization shall apply risk management throughout product '
                    . 'realization and maintain records of it (Section 7.1, with ISO 14971).',
                artefact: 'The risk management file required by ISO 14971, with hazard analysis, '
                    . 'risk control measures and the residual risk evaluation.',
            ),

            ControlDeclaration::probed(
                id: 'ISO13485-TRACE-001',
                framework: ComplianceFramework::Iso13485,
                title: 'Traceability (Section 7.5.9)',
                requirement: 'The organization shall document procedures for traceability and shall '
                    . 'retain records that permit a device to be traced to the components and '
                    . 'conditions of its production (Section 7.5.9).',
                probe: new TamperEvidentAuditProbe(),
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'ISO13485-COMPLAINT-001',
                framework: ComplianceFramework::Iso13485,
                title: 'Complaint Handling (Section 8.2.2)',
                requirement: 'The organization shall document procedures for timely complaint handling '
                    . 'and shall record the investigation of each complaint (Section 8.2.2).',
                artefact: 'The complaint register, with the investigation record for each complaint '
                    . 'and the link to any corrective action it caused.',
            ),

            ControlDeclaration::probed(
                id: 'ISO13485-MONITOR-001',
                framework: ComplianceFramework::Iso13485,
                title: 'Monitoring and Measurement (Section 8.2)',
                requirement: 'The organization shall plan and implement monitoring and measurement to '
                    . 'demonstrate conformity of the product and the effectiveness of the '
                    . 'quality management system (Section 8.2).',
                probe: new ContinuousMonitoringProbe(),
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'ISO13485-DOC-001',
                framework: ComplianceFramework::Iso13485,
                title: 'Documentation Requirements (Section 4.2)',
                requirement: 'The organization shall document a quality management system and control '
                    . 'the documented information it requires (Section 4.2).',
                artefact: 'The quality manual, the document control procedure, and the medical '
                    . 'device file for each device family.',
            ),
        ];
    }
}
