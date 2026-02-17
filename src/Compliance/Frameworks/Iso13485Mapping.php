<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Frameworks;

use Pulsar\Api\Internal;
use Pulsar\Compliance\Control;
use Pulsar\Compliance\ControlCatalog;
use Pulsar\Compliance\ControlStatus;

/**
 * Registers ISO 13485:2016 QMS controls into the catalog.
 *
 * Maps Pulsar medical device extension features to ISO 13485 quality
 * management system requirements they provide tooling support for.
 *
 * NOTE: ISO 13485 is a QMS standard requiring organizational processes.
 * Pulsar provides tooling to document and track these processes, not to
 * replace the organizational commitment required for certification.
 *
 * @see ISO 13485:2016 Medical devices: Quality management systems
 */
#[Internal(reason: 'Framework-internal control registration; use ControlCatalog for public access')]
final class Iso13485Mapping
{
    /**
     * Register ISO 13485 controls into the given catalog.
     */
    public static function register(ControlCatalog $catalog): void
    {
        $catalog->register(new Control(
            id: 'ISO13485-DC-001',
            framework: 'iso13485',
            title: 'Design Controls (Section 7.3)',
            description: 'Design control record tracking with phases (planning through complete), '
                . 'design inputs/outputs, verification/validation results, and review notes. '
                . 'Supports design change management per 7.3.9.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['design_controls', 'design_verification', 'design_validation', 'design_changes'],
        ));

        $catalog->register(new Control(
            id: 'ISO13485-CAPA-001',
            framework: 'iso13485',
            title: 'CAPA: Corrective Action (Section 8.5.2)',
            description: 'Corrective action tracking: root cause analysis, planned actions, '
                . 'implementation evidence, effectiveness verification. Links to affected '
                . 'devices and complaint IDs.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['corrective_action', 'root_cause_analysis', 'effectiveness_verification'],
        ));

        $catalog->register(new Control(
            id: 'ISO13485-CAPA-002',
            framework: 'iso13485',
            title: 'CAPA: Preventive Action (Section 8.5.3)',
            description: 'Preventive action tracking: risk assessment of potential nonconformities, '
                . 'planned actions, implementation evidence, effectiveness verification. '
                . 'Triggered by trend analysis or audit findings.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['preventive_action', 'risk_assessment', 'proactive_quality'],
        ));

        $catalog->register(new Control(
            id: 'ISO13485-RISK-001',
            framework: 'iso13485',
            title: 'Risk Management (Section 7.1 + ISO 14971)',
            description: 'Risk management file with hazard identification, severity/probability '
                . 'assessment, risk control measures, and residual risk evaluation. '
                . 'Aligned with ISO 14971:2019.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['risk_management_file', 'hazard_analysis', 'risk_control'],
        ));

        $catalog->register(new Control(
            id: 'ISO13485-TRACE-001',
            framework: 'iso13485',
            title: 'Traceability (Section 7.5.9)',
            description: 'Device traceability through UDI system supporting lot and serial number '
                . 'tracking. Enables identification of devices for field safety corrective actions.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['device_traceability', 'lot_tracking', 'serial_tracking'],
        ));

        $catalog->register(new Control(
            id: 'ISO13485-COMPLAINT-001',
            framework: 'iso13485',
            title: 'Complaint Handling (Section 8.2.2)',
            description: 'Adverse event and complaint tracking through post-market surveillance '
                . 'reports. Links complaints to CAPA records for systematic resolution.',
            status: ControlStatus::Partial,
            frameworkFeatures: ['complaint_tracking', 'adverse_event_reporting'],
        ));

        $catalog->register(new Control(
            id: 'ISO13485-MONITOR-001',
            framework: 'iso13485',
            title: 'Monitoring and Measurement (Section 8.2)',
            description: 'Post-market surveillance with trend analysis interface for detecting '
                . 'significant increases in adverse events. Supports PMS, PSUR, and PMCF reporting.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['post_market_surveillance', 'trend_analysis', 'pmcf'],
        ));

        $catalog->register(new Control(
            id: 'ISO13485-DOC-001',
            framework: 'iso13485',
            title: 'Documentation Requirements (Section 4.2)',
            description: 'Structured DTOs with toArray() serialization for all QMS records: '
                . 'design controls, CAPA, risk management, clinical data, PMS reports. '
                . 'Supports audit trail and record retention.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['qms_documentation', 'record_serialization', 'audit_trail'],
        ));
    }
}
