<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Frameworks;

use Pulsar\Api\Internal;
use Pulsar\Compliance\Control;
use Pulsar\Compliance\ControlCatalog;
use Pulsar\Compliance\ControlStatus;

/**
 * Registers EU MDR 2017/745 controls into the catalog.
 *
 * Maps Pulsar medical device extension features to MDR regulatory
 * requirements they provide tooling support for.
 *
 * @see https://eur-lex.europa.eu/legal-content/EN/TXT/?uri=CELEX:32017R0745
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
#[Internal(reason: 'Framework-internal control registration; use ControlCatalog for public access')]
final class MdrMapping
{
    /**
     * Register MDR controls into the given catalog.
     */
    public static function register(ControlCatalog $catalog): void
    {
        $catalog->register(new Control(
            id: 'MDR-UDI-001',
            framework: 'mdr',
            title: 'Unique Device Identification (Article 27)',
            description: 'UDI system with DI/PI components, issuing agency support (GS1, HIBCC, ICCBBA, IFA), '
                . 'format validation including GTIN-14 check digit, and device registry for tracking.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['udi_identifier', 'udi_validation', 'udi_registry', 'device_tracking'],
        ));

        $catalog->register(new Control(
            id: 'MDR-CLASS-001',
            framework: 'mdr',
            title: 'Device Classification (Annex VIII)',
            description: 'Risk classification system supporting Class I, IIa, IIb, and III devices '
                . 'with status tracking (Active, Recalled, Suspended, Withdrawn, Expired).',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['device_classification', 'device_status_tracking'],
        ));

        $catalog->register(new Control(
            id: 'MDR-PMS-001',
            framework: 'mdr',
            title: 'Post-Market Surveillance (Articles 83-86)',
            description: 'PMS reporting with adverse event summaries, trend analysis interface, '
                . 'and support for PMS reports, PSURs, and PMCF reports.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['pms_reporting', 'adverse_event_tracking', 'trend_analysis', 'pmcf'],
        ));

        $catalog->register(new Control(
            id: 'MDR-VIG-001',
            framework: 'mdr',
            title: 'Vigilance Reporting (Article 87)',
            description: 'Serious incident reporting with MDR-compliant categorization (death, '
                . 'serious deterioration of health, public health threat). Supports initial, '
                . 'follow-up, and final report lifecycle with competent authority tracking.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['vigilance_reporting', 'serious_incident_classification', 'report_lifecycle'],
        ));

        $catalog->register(new Control(
            id: 'MDR-CLIN-001',
            framework: 'mdr',
            title: 'Clinical Investigations (Articles 62-82)',
            description: 'Clinical investigation lifecycle tracking with ethics committee approval, '
                . 'competent authority notification, subject enrollment, and endpoint tracking.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['clinical_investigation', 'ethics_approval', 'competent_authority'],
        ));

        $catalog->register(new Control(
            id: 'MDR-RISK-001',
            framework: 'mdr',
            title: 'Risk Management (per ISO 14971)',
            description: 'Risk management file with hazard analysis, severity/probability assessment, '
                . 'risk control tracking, and residual risk evaluation per ISO 14971.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['risk_management', 'hazard_analysis', 'risk_controls'],
        ));

        $catalog->register(new Control(
            id: 'MDR-TRACE-001',
            framework: 'mdr',
            title: 'Traceability (Article 25)',
            description: 'Device traceability through UDI system with lot number and serial number '
                . 'tracking. Supports recall identification via lot-based and serial-based lookup.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['device_traceability', 'lot_tracking', 'serial_tracking'],
        ));

        $catalog->register(new Control(
            id: 'MDR-EUDAMED-001',
            framework: 'mdr',
            title: 'EUDAMED Integration (Article 33)',
            description: 'Registry interface designed for EUDAMED integration. Default in-memory '
                . 'implementation; production deployments provide persistent EUDAMED-connected adapter.',
            status: ControlStatus::Partial,
            frameworkFeatures: ['eudamed_registry', 'device_registration'],
        ));
    }
}
