<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Frameworks;

use Pulsar\Api\Internal;
use Pulsar\Compliance\Control;
use Pulsar\Compliance\ControlCatalog;
use Pulsar\Compliance\ControlStatus;

/**
 * Registers DORA (EU 2022/2554) controls into the catalog.
 *
 * Maps Pulsar DORA extension features to Digital Operational Resilience Act
 * requirements for financial entities.
 *
 * @see https://eur-lex.europa.eu/legal-content/EN/TXT/?uri=CELEX:32022R2554
 */
#[Internal(reason: 'Framework-internal control registration; use ControlCatalog for public access')]
final class DoraMapping
{
    /**
     * Register DORA controls into the given catalog.
     */
    public static function register(ControlCatalog $catalog): void
    {
        $catalog->register(new Control(
            id: 'DORA-RISK-001',
            framework: 'dora',
            title: 'ICT Risk Management Framework (Articles 5-16)',
            description: 'ICT asset registry with criticality classification, dependency tracking, '
                . 'recovery objectives (RTO/RPO), and third-party provider mapping. '
                . 'Risk categories cover cyber attacks, system failures, and concentration risk.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['ict_asset_registry', 'risk_classification', 'dependency_tracking', 'recovery_objectives'],
        ));

        $catalog->register(new Control(
            id: 'DORA-INC-001',
            framework: 'dora',
            title: 'ICT Incident Management (Articles 17-23)',
            description: 'Incident classification (major/non-major), reporting phase tracking '
                . '(detection, initial 4h, intermediate 72h, final 1 month), affected service '
                . 'and client tracking, financial impact estimation.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['incident_classification', 'incident_reporting_timeline', 'incident_impact_tracking'],
        ));

        $catalog->register(new Control(
            id: 'DORA-INC-002',
            framework: 'dora',
            title: 'ICT Incident Compliance Events',
            description: 'Compliance event types for ICT incident detection (IctIncidentDetected) '
                . 'and recovery initiation (RecoveryInitiated) with audit trail support.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['incident_events', 'recovery_events', 'compliance_audit_trail'],
        ));

        $catalog->register(new Control(
            id: 'DORA-TEST-001',
            framework: 'dora',
            title: 'Digital Operational Resilience Testing (Articles 24-27)',
            description: 'Resilience test records covering vulnerability scanning, penetration testing, '
                . 'network security, source code review, scenario-based testing, and threat-led '
                . 'penetration testing (TLPT). Compliance events for test completion.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['resilience_testing', 'vulnerability_scanning', 'penetration_testing', 'tlpt'],
        ));

        $catalog->register(new Control(
            id: 'DORA-TPR-001',
            framework: 'dora',
            title: 'ICT Third-Party Risk Management (Articles 28-44)',
            description: 'Third-party provider register with risk level assessment, contract tracking, '
                . 'subcontractor chain visibility, exit strategy documentation, and concentration '
                . 'risk analysis. Compliance events for risk assessment completion.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['third_party_register', 'concentration_risk', 'exit_strategy', 'subcontractor_tracking'],
        ));

        $catalog->register(new Control(
            id: 'DORA-SHARE-001',
            framework: 'dora',
            title: 'Information Sharing (Article 45)',
            description: 'Cyber threat indicator sharing with severity classification, affected sector '
                . 'tracking, and mitigation recommendations. Supports indicators of compromise, '
                . 'tactics, techniques, and procedures.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['threat_intelligence', 'indicator_sharing', 'sector_coordination'],
        ));

        $catalog->register(new Control(
            id: 'DORA-BCM-001',
            framework: 'dora',
            title: 'Business Continuity Management (Article 11)',
            description: 'Recovery time and point objectives per ICT asset, recovery plan tracking, '
                . 'and integration with Pulsar resilience patterns (circuit breaker, retry policy).',
            status: ControlStatus::Partial,
            frameworkFeatures: ['business_continuity', 'recovery_planning', 'resilience_integration'],
        ));

        $catalog->register(new Control(
            id: 'DORA-GOV-001',
            framework: 'dora',
            title: 'ICT Governance (Article 5)',
            description: 'Compliance profile integration: 4-hour breach notification deadline, '
                . 'mandatory resilience testing, encryption requirements, and tamper-evident audit.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['compliance_profile', 'breach_notification', 'governance_integration'],
        ));
    }
}
